<?php

namespace Tests\Feature\Assessment;

use App\Models\BaselineAssessment;
use App\Models\BaselineAssessmentItem;
use App\Models\BaselineQuestionSnapshot;
use App\Models\CareerRole;
use App\Models\Role;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BaselineAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    private Role $companyRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        $this->companyRole = Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);

        Config::set('services.data_science.baseline.enabled', true);
        Config::set('services.data_science.baseline.version', 'v1.0');

        // Deterministic selection keeps variable-count assertions stable.
        Config::set('services.baseline_assessment.deterministic_selection', true);

        // US-INT-01: the service credential is mandatory on every
        // Laravel -> FastAPI intelligence request, baseline included.
        Config::set('services.data_science.service_token', 'test-service-token');
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization & start
    |--------------------------------------------------------------------------
    */

    public function test_unauthenticated_user_is_rejected(): void
    {
        $this->postJson('/api/v1/baseline-assessments')
            ->assertStatus(401);
    }

    public function test_non_learner_cannot_start_baseline_assessment(): void
    {
        $user = $this->createUserWithRole($this->companyRole);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/baseline-assessments')
            ->assertStatus(403)
            ->assertJsonPath('code', 'LEARNER_ONLY');
    }

    public function test_start_requires_career_role_id(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/baseline-assessments')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['career_role_id']);
    }

    public function test_start_rejects_unknown_career_role(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['career_role_id']);
    }

    public function test_start_rejects_unapproved_career_role(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $pending = $this->careerRole('Pending Role', 'draft', ['sql']);
        $this->seedItems();

        $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $pending->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['career_role_id']);
    }

    public function test_start_creates_dynamic_assessment_with_snapshot(): void
    {
        Config::set('services.data_science.baseline.version', 'v1.0');
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        $role = $this->careerRole('Data Analyst', 'approved', ['python', 'sql']);
        $this->seedItems();

        $response = $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id]);

        $response->assertStatus(201)
            ->assertJsonPath('data.assessment_type', 'baseline')
            ->assertJsonPath('data.assessment_version', 'v1.0')
            ->assertJsonPath('data.career_role_id', $role->id)
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonStructure([
                'data' => [
                    'questions' => [['item_id', 'item_type', 'options']],
                    'skill_coverage' => ['total_questions', 'skill_count', 'skills'],
                    'snapshot_metadata' => ['career_role_id', 'career_role_version', 'selected_at'],
                ],
            ]);

        $this->assertDatabaseHas('baseline_assessments', [
            'student_profile_id' => $profile->id,
            'career_role_id' => $role->id,
            'assessment_type' => 'baseline',
            'assessment_version' => 'v1.0',
            'status' => 'in_progress',
        ]);

        $assessmentId = $response->json('data.id');

        // Snapshot rows freeze the role/skill mapping for the picked items.
        $this->assertGreaterThan(0, BaselineQuestionSnapshot::where('baseline_assessment_id', $assessmentId)->count());

        $this->assertDatabaseHas('baseline_question_snapshots', [
            'baseline_assessment_id' => $assessmentId,
            'career_role_id' => $role->id,
        ]);
    }

    public function test_start_ignores_client_supplied_version(): void
    {
        Config::set('services.data_science.baseline.version', 'v1.0');
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $role = $this->careerRole('Data Analyst', 'approved', ['sql']);
        $this->seedItems();

        $this->postJson('/api/v1/baseline-assessments', [
            'career_role_id' => $role->id,
            'assessment_version' => 'v999',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.assessment_version', 'v1.0');
    }

    public function test_start_rejects_second_attempt_for_same_version(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $role = $this->careerRole('Data Analyst', 'approved', ['sql']);
        $this->seedItems();

        $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])->assertStatus(201);

        $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ASSESSMENT_ALREADY_EXISTS');
    }

    /*
    |--------------------------------------------------------------------------
    | Question selection & coverage
    |--------------------------------------------------------------------------
    */

    public function test_start_selects_variable_question_counts_per_skill(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $role = $this->careerRole('Data Analyst', 'approved', ['python', 'sql']);

        // python: 3 active items, sql: 1 active item.
        $python = Skill::where('slug', 'python')->first();
        $sql = Skill::where('slug', 'sql')->first();

        foreach (['python-001', 'python-002', 'python-003'] as $itemId) {
            $this->item($itemId, $python->id, 'single_choice', ['A', 'B', 'C', 'D']);
        }
        $this->item('sql-001', $sql->id, 'single_choice', ['A', 'B', 'C', 'D']);

        $response = $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $coverage = $response->json('data.skill_coverage.skills');
        $bySkill = collect($coverage)->keyBy('skill_slug');

        // Every required skill is covered with at least one question.
        $this->assertCount(2, $coverage);
        $this->assertTrue($bySkill['sql']['covered']);
        $this->assertTrue($bySkill['python']['covered']);
        $this->assertGreaterThanOrEqual(1, $bySkill['sql']['question_count']);
        $this->assertLessThanOrEqual(3, $bySkill['python']['question_count']);
    }

    public function test_start_fails_with_clear_error_when_required_skill_has_no_questions(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $role = $this->careerRole('Data Analyst', 'approved', ['python', 'sql']);
        // Only SQL has a question; python is uncovered.
        $sql = Skill::where('slug', 'sql')->first();
        $this->item('sql-001', $sql->id, 'single_choice', ['A', 'B', 'C', 'D']);

        $response = $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INSUFFICIENT_QUESTION_COVERAGE');

        $this->assertNotEmpty($response->json('details.uncovered_skills'));

        // Nothing persisted.
        $this->assertDatabaseCount('baseline_assessments', 0);
    }

    public function test_start_fails_when_career_role_has_no_required_skills(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $role = $this->careerRole('Empty Role', 'approved', []);
        $this->seedItems();

        $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CAREER_ROLE_NO_SKILLS');
    }

    public function test_snapshot_is_immutable_after_question_bank_changes(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $role = $this->careerRole('Data Analyst', 'approved', ['sql']);
        $this->seedItems();

        $created = $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $assessmentId = $created->json('data.id');
        $originalItemIds = collect($created->json('data.questions'))->pluck('item_id')->sort()->values()->all();

        // Mutate the live bank: deactivate the selected item and add a new one.
        $sql = Skill::where('slug', 'sql')->first();
        BaselineAssessmentItem::where('assessment_version', 'v1.0')->update(['is_active' => false]);
        $this->item('sql-new', $sql->id, 'single_choice', ['X', 'Y']);

        $reloaded = $this->getJson("/api/v1/baseline-assessments/{$assessmentId}")
            ->assertStatus(200);

        $reloadedItemIds = collect($reloaded->json('data.questions'))->pluck('item_id')->sort()->values()->all();

        $this->assertSame($originalItemIds, $reloadedItemIds);
        $this->assertNotContains('sql-new', $reloadedItemIds);
    }

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    */

    public function test_show_returns_questions_without_correct_answers(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $role = $this->careerRole('Data Analyst', 'approved', ['sql']);
        $this->seedItems();

        $assessmentId = $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->json('data.id');

        $response = $this->getJson("/api/v1/baseline-assessments/{$assessmentId}")
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['questions' => [['item_id', 'item_type', 'options', 'skill_id']]]]);

        $payload = json_encode($response->json('data'));

        $this->assertStringNotContainsString('correct_answer', $payload);
        $this->assertStringNotContainsString('scoring_rule', $payload);
    }

    public function test_show_returns_resume_state(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $assessment = BaselineAssessment::forceCreate([
            'student_profile_id' => $this->learnerProfile($user)->id,
            'assessment_type' => 'baseline',
            'assessment_version' => 'v1.0',
            'status' => 'in_progress',
            'progress' => ['current_question' => 3],
        ]);

        $this->getJson("/api/v1/baseline-assessments/{$assessment->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.progress.current_question', 3);
    }

    public function test_learner_cannot_show_another_learners_assessment(): void
    {
        [$user, $profile] = $this->createLearner();
        [$otherUser] = $this->createLearner();
        Sanctum::actingAs($otherUser);

        $assessment = BaselineAssessment::forceCreate([
            'student_profile_id' => $profile->id,
            'assessment_type' => 'baseline',
            'assessment_version' => 'v1.0',
            'status' => 'in_progress',
        ]);

        $this->getJson("/api/v1/baseline-assessments/{$assessment->id}")
            ->assertStatus(404)
            ->assertJsonPath('code', 'ASSESSMENT_NOT_FOUND');

        $this->assertNotEquals($user->id, $otherUser->id);
    }

    public function test_progress_saves_resume_state(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $assessment = BaselineAssessment::forceCreate([
            'student_profile_id' => $this->learnerProfile($user)->id,
            'assessment_type' => 'baseline',
            'assessment_version' => 'v1.0',
            'status' => 'in_progress',
        ]);

        $response = $this->patchJson("/api/v1/baseline-assessments/{$assessment->id}", [
            'progress' => ['current_question' => 7, 'answered' => [1, 2, 3, 4, 5, 6, 7]],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.progress.current_question', 7);
    }

    public function test_progress_on_completed_assessment_is_rejected(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $assessment = BaselineAssessment::forceCreate([
            'student_profile_id' => $this->learnerProfile($user)->id,
            'assessment_type' => 'baseline',
            'assessment_version' => 'v1.0',
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $this->patchJson("/api/v1/baseline-assessments/{$assessment->id}", ['progress' => ['x' => 1]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ASSESSMENT_ALREADY_COMPLETED');
    }

    /*
    |--------------------------------------------------------------------------
    | Submission validation
    |--------------------------------------------------------------------------
    */

    public function test_submit_rejects_incomplete_responses(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $assessment = BaselineAssessment::forceCreate([
            'student_profile_id' => $this->learnerProfile($user)->id,
            'assessment_type' => 'baseline',
            'assessment_version' => 'v1.0',
            'status' => 'in_progress',
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['responses']);
    }

    public function test_submit_rejects_question_not_in_snapshot(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment, $role] = $this->startDynamicAssessment($user, ['sql']);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['question_id' => 'not-in-snapshot', 'answer' => 'A'],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'UNAUTHORIZED_QUESTION');
    }

    public function test_submit_rejects_duplicate_question_responses(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->startDynamicAssessment($user, ['sql']);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['question_id' => 'sql-001', 'answer' => 'A'],
                ['question_id' => 'sql-001', 'answer' => 'B'],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DUPLICATE_RESPONSE');
    }

    public function test_submit_rejects_answer_outside_allowed_options(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->startDynamicAssessment($user, ['sql']);
        $sql = Skill::where('slug', 'sql')->first();

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'skills' => [
                    ['slug' => $sql->slug, 'level' => 3.0, 'confidence' => 0.8],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['question_id' => 'sql-001', 'answer' => 'Z'],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_ANSWER_OPTION');
    }

    public function test_submit_rejects_missing_responses_for_snapshot_questions(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        // Two required skills -> at least two questions; only answer one.
        [$assessment] = $this->startDynamicAssessment($user, ['python', 'sql']);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['question_id' => 'sql-001', 'answer' => 'A'],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INCOMPLETE_RESPONSES');

        $this->assertDatabaseHas('baseline_assessments', [
            'id' => $assessment->id,
            'status' => 'in_progress',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Intelligence integration & persistence
    |--------------------------------------------------------------------------
    */

    public function test_submit_uses_intelligence_service_result(): void
    {
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->startDynamicAssessment($user, ['python', 'sql']);
        $python = Skill::where('slug', 'python')->first();
        $sql = Skill::where('slug', 'sql')->first();

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'student_profile_id' => $profile->id,
                'overall_score' => 60,
                'skills' => [
                    ['skill_id' => $python->id, 'slug' => $python->slug, 'level' => 3.0, 'confidence' => 0.7],
                    ['skill_id' => $sql->id, 'slug' => $sql->slug, 'level' => 2.5, 'confidence' => 0.6],
                ],
            ], 200),
        ]);

        $questions = $this->snapshotItemIds($assessment);

        $response = $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => $this->responsesFor($assessment),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonStructure(['data' => ['completed_at']]);

        $this->assertNotNull($response->json('data.completed_at'));

        $this->assertDatabaseHas('baseline_assessments', [
            'id' => $assessment->id,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('skill_evidences', [
            'student_profile_id' => $profile->id,
            'skill_id' => $python->id,
            'source' => 'assessment_test',
            'normalized_value' => 3.00,
            'source_record_type' => BaselineAssessment::class,
            'source_record_id' => $assessment->id,
        ]);

        $this->assertDatabaseHas('skill_evaluations', [
            'student_profile_id' => $profile->id,
            'skill_id' => $python->id,
            'level' => 3.00,
            'confidence' => 70.00,
            'algorithm_version' => 'baseline-v1.0',
        ]);

        $this->assertNotEmpty($questions);
    }

    public function test_submit_forwards_snapshot_metadata_to_intelligence(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment, $role] = $this->startDynamicAssessment($user, ['sql']);
        $sql = Skill::where('slug', 'sql')->first();

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'skills' => [
                    ['slug' => $sql->slug, 'level' => 3.0, 'confidence' => 0.8],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => $this->responsesFor($assessment),
        ])->assertStatus(200);

        Http::assertSent(function ($request) use ($role) {
            $payload = $request->data();

            return ($payload['career_role_id'] ?? null) === $role->id
                && ($payload['career_role_version'] ?? null) === (int) $role->version
                && ! empty($payload['question_ids'])
                && in_array('sql-001', $payload['question_ids'], true);
        });
    }

    public function test_submit_rejects_out_of_scope_skill_from_intelligence(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->startDynamicAssessment($user, ['sql']);
        // A skill that exists but is NOT part of the role's snapshot.
        $outOfScope = Skill::forceCreate(['name' => 'Gardening', 'slug' => 'gardening', 'status' => 'active']);

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'skills' => [
                    ['slug' => $outOfScope->slug, 'level' => 3.0, 'confidence' => 0.8],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => $this->responsesFor($assessment),
        ])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_SKILL_OUT_OF_SCOPE');

        // Rollback: nothing persisted, assessment still resumable.
        $this->assertDatabaseMissing('skill_evaluations', ['skill_id' => $outOfScope->id]);
        $this->assertDatabaseHas('baseline_assessments', [
            'id' => $assessment->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_submit_rolls_back_when_intelligence_returns_invalid_skill(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->startDynamicAssessment($user, ['sql']);

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'skills' => [
                    ['slug' => 'does-not-exist', 'level' => 3.0, 'confidence' => 0.8],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => $this->responsesFor($assessment),
        ])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        $this->assertDatabaseCount('skill_evidences', 0);
        $this->assertDatabaseHas('baseline_assessments', [
            'id' => $assessment->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_submit_rejects_out_of_range_level_from_intelligence(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->startDynamicAssessment($user, ['sql']);
        $sql = Skill::where('slug', 'sql')->first();

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'skills' => [
                    ['slug' => $sql->slug, 'level' => 8.0, 'confidence' => 0.8],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => $this->responsesFor($assessment),
        ])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');
    }

    public function test_submit_fails_safely_when_intelligence_not_configured(): void
    {
        Config::set('services.data_science.baseline.enabled', false);
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->startDynamicAssessment($user, ['sql']);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => $this->responsesFor($assessment),
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'BASELINE_INTEGRATION_NOT_CONFIGURED');

        $this->assertDatabaseHas('baseline_assessments', [
            'id' => $assessment->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_submit_fails_safely_when_intelligence_unavailable(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->startDynamicAssessment($user, ['sql']);

        Http::fake([
            '*/api/v1/baseline' => Http::response('', 503),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => $this->responsesFor($assessment),
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'INTELLIGENCE_SERVICE_ERROR');

        $this->assertDatabaseHas('baseline_assessments', [
            'id' => $assessment->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_duplicate_submit_is_rejected_and_persists_once(): void
    {
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->startDynamicAssessment($user, ['sql']);
        $sql = Skill::where('slug', 'sql')->first();

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'skills' => [
                    ['slug' => $sql->slug, 'level' => 3.0, 'confidence' => 0.8],
                ],
            ], 200),
        ]);

        $payload = ['responses' => $this->responsesFor($assessment)];

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", $payload)
            ->assertStatus(200);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'ASSESSMENT_ALREADY_COMPLETED');

        // Exactly one evidence/evaluation row despite two submits.
        $this->assertDatabaseCount('skill_evidences', 1);
        $this->assertDatabaseCount('skill_evaluations', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | Readiness regression
    |--------------------------------------------------------------------------
    */

    public function test_completed_baseline_produces_evaluations_for_readiness(): void
    {
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        $role = $this->careerRole('Data Analyst', 'approved', ['sql']);
        $this->seedItems();

        $assessmentId = $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->json('data.id');

        $assessment = BaselineAssessment::find($assessmentId);
        $sql = Skill::where('slug', 'sql')->first();

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'skills' => [
                    ['slug' => $sql->slug, 'level' => 3.5, 'confidence' => 0.9],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessmentId}/submit", [
            'responses' => $this->responsesFor($assessment),
        ])->assertStatus(200);

        // The role's single required skill now has an evaluation, so
        // readiness completeness (ASSESSMENT_INCOMPLETE) is satisfied.
        $this->assertDatabaseHas('skill_evaluations', [
            'student_profile_id' => $profile->id,
            'skill_id' => $sql->id,
            'level' => 3.50,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Create a learner, an approved role with the given skills, seed the
     * question bank, then start a dynamic assessment.
     *
     * @param  array<int, string>  $skillSlugs
     */
    private function startDynamicAssessment(User $user, array $skillSlugs): array
    {
        $role = $this->careerRole('Data Analyst', 'approved', $skillSlugs);
        $this->seedItems();

        $assessmentId = $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->json('data.id');

        return [BaselineAssessment::with('questionSnapshots.item')->findOrFail($assessmentId), $role];
    }

    /**
     * Valid answers for every snapshot question of an assessment.
     */
    private function responsesFor(BaselineAssessment $assessment): array
    {
        $assessment->loadMissing('questionSnapshots.item');

        return $assessment->questionSnapshots
            ->map(function ($snapshot) {
                $options = $snapshot->item->options;

                return [
                    'question_id' => (string) $snapshot->item->item_id,
                    'answer' => (string) ($options[0] ?? 'A'),
                ];
            })
            ->values()
            ->all();
    }

    private function snapshotItemIds(BaselineAssessment $assessment): array
    {
        return $assessment->questionSnapshots
            ->map(fn ($snapshot) => (string) $snapshot->item->item_id)
            ->values()
            ->all();
    }

    /**
     * Role with the named (already-seeded) skills attached.
     *
     * @param  array<int, string>  $skillSlugs
     */
    private function careerRole(string $title, string $status, array $skillSlugs): CareerRole
    {
        $role = CareerRole::create([
            'title' => $title,
            'slug' => Str::slug($title).'-'.uniqid(),
            'version' => 1,
            'status' => $status,
            'effective_date' => now()->toDateString(),
        ]);

        foreach ($skillSlugs as $index => $slug) {
            $skill = Skill::firstOrCreate(
                ['slug' => $slug],
                ['name' => ucfirst($slug), 'status' => 'active'],
            );

            $role->roleSkills()->create([
                'skill_id' => $skill->id,
                'required_level' => 3.0,
                'importance_weight' => round(0.9 - ($index * 0.1), 3),
                'is_critical' => $index === 0,
            ]);
        }

        return $role;
    }

    private function seedItems(): void
    {
        $sql = Skill::firstOrCreate(['slug' => 'sql'], ['name' => 'SQL', 'status' => 'active']);
        $python = Skill::firstOrCreate(['slug' => 'python'], ['name' => 'Python', 'status' => 'active']);

        $this->item('sql-001', $sql->id, 'single_choice', ['A', 'B', 'C', 'D']);
        $this->item('python-001', $python->id, 'single_choice', ['A', 'B', 'C', 'D']);
    }

    private function item(string $itemId, int $skillId, string $type, array $options): BaselineAssessmentItem
    {
        return BaselineAssessmentItem::updateOrCreate(
            ['assessment_version' => 'v1.0', 'item_id' => $itemId],
            [
                'item_type' => $type,
                'skill_id' => $skillId,
                'options' => $options,
                'correct_answer' => $type === 'single_choice' ? $options[0] : null,
                'scoring_rule' => null,
                'weight' => 1.000,
                'is_active' => true,
            ],
        );
    }

    private function createLearner(): array
    {
        $user = $this->createUserWithRole($this->learnerRole);

        $profile = StudentProfile::forceCreate([
            'user_id' => $user->id,
        ]);

        return [$user, $profile];
    }

    private function learnerProfile(User $user): StudentProfile
    {
        return $user->studentProfile;
    }

    private function createUserWithRole(Role $role): User
    {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }
}
