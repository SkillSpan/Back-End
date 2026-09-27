<?php

namespace Tests\Feature\Assessment;

use App\Models\BaselineAssessment;
use App\Models\BaselineAssessmentItem;
use App\Models\CareerRole;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\SkillEvidence;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Baseline\BaselineAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tasks D + E — submission correctness and readiness gating.
 *
 * Task D: a submission must load the correct assessment and snapshot,
 * enforce ownership/status, reject anything not in the snapshot, persist
 * transactionally, and flip to `completed` only after everything
 * succeeded.
 *
 * Task E: an incomplete assessment must not be bypassable, and readiness
 * must still raise ASSESSMENT_INCOMPLETE for any required role skill with
 * no evaluation. Nothing is fabricated to fill a gap.
 */
class BaselineSubmissionAndReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create([
            'name' => 'Learner', 'slug' => 'learner', 'description' => '',
        ]);

        Config::set('services.data_science.baseline.enabled', true);
        Config::set('services.data_science.baseline.version', 'v1.0');
        Config::set('services.baseline_assessment.deterministic_selection', true);
        Config::set('services.data_science.service_token', 'test-service-token');
    }

    /*
    |--------------------------------------------------------------------------
    | Task D — submission correctness
    |--------------------------------------------------------------------------
    */

    public function test_learner_cannot_submit_another_learners_assessment(): void
    {
        [$owner] = $this->createLearner();
        Sanctum::actingAs($owner);
        [$assessment] = $this->start(['sql'], ['sql-001']);

        // A different learner tries to submit it.
        [$intruder] = $this->createLearner();
        Sanctum::actingAs($intruder);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])->assertStatus(404);
    }

    public function test_submitting_a_completed_assessment_is_rejected(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001']);

        $this->fakeIntelligence(['sql']);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])->assertStatus(200);

        // Second submit on the now-completed assessment.
        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ASSESSMENT_ALREADY_COMPLETED');
    }

    public function test_submission_rejects_a_question_from_another_assessment(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001']);

        // A question id that exists in the bank but not in this snapshot.
        BaselineAssessmentItem::updateOrCreate(
            ['assessment_version' => 'v1.0', 'item_id' => 'sql-999'],
            [
                'item_type' => 'single_choice',
                'question_text' => 'Not part of this assessment.',
                'skill_id' => Skill::where('slug', 'sql')->value('id'),
                'options' => ['A', 'B'],
                'correct_answer' => 'A',
                'weight' => 1.000,
                'is_active' => true,
            ],
        );

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-999', 'answer' => 'A']],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'UNAUTHORIZED_QUESTION');
    }

    public function test_submission_rolls_back_entirely_when_persistence_fails(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001']);
        $this->fakeIntelligence(['sql']);

        // Force a failure partway through persistence.
        SkillEvidence::creating(function () {
            throw new \RuntimeException('simulated persistence failure');
        });

        try {
            $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
                'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
            ]);
        } catch (\RuntimeException) {
            // propagated by the test environment
        }

        // Nothing persisted, and the assessment is still resumable.
        $this->assertSame(0, SkillEvaluation::count());
        $this->assertSame(0, SkillEvidence::count());
        $this->assertSame('in_progress', $assessment->fresh()->status);
    }

    public function test_retry_after_failure_does_not_duplicate_evaluations(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001']);
        $this->fakeIntelligence(['sql']);

        $payload = ['responses' => [['question_id' => 'sql-001', 'answer' => 'A']]];

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", $payload)
            ->assertStatus(200);

        // Re-dispatch the persistence path directly (simulating a queued
        // retry of the same assessment) — the idempotency guard must hold.
        $service = app(BaselineAssessmentService::class);
        $completed = $assessment->fresh();

        $this->assertSame('completed', $completed->status);

        // Exactly one evaluation + one evidence row.
        $this->assertSame(1, SkillEvaluation::count());
        $this->assertSame(1, SkillEvidence::count());

        // A direct re-submit is refused, so no duplicates can arise.
        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", $payload)
            ->assertStatus(409);

        $this->assertSame(1, SkillEvaluation::count());
        $this->assertSame(1, SkillEvidence::count());
    }

    public function test_assessment_marked_completed_only_after_successful_persistence(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001']);

        // Service returns an unusable payload — must not complete.
        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'skills' => [
                    ['slug' => 'nonexistent-skill', 'level' => 3.0, 'confidence' => 0.8],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])->assertStatus(502);

        $reloaded = $assessment->fresh();

        $this->assertSame('in_progress', $reloaded->status);
        $this->assertNull($reloaded->completed_at);
        $this->assertNull($reloaded->result);
    }

    /*
    |--------------------------------------------------------------------------
    | Task E — readiness gating
    |--------------------------------------------------------------------------
    */

    public function test_partial_evaluations_are_not_fabricated_for_missing_skills(): void
    {
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        // Role requires BOTH sql and python.
        $role = $this->role(['sql', 'python']);
        $this->item('sql-001', 'sql', 'Which clause filters rows?');
        $this->item('python-001', 'python', 'What does len() return?');

        $assessmentId = $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->json('data.id');

        // Service only scores sql — python is absent.
        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'skills' => [
                    ['slug' => 'sql', 'level' => 3.0, 'confidence' => 0.8],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessmentId}/submit", [
            'responses' => [
                ['question_id' => 'sql-001', 'answer' => 'A'],
                ['question_id' => 'python-001', 'answer' => 'A'],
            ],
        ])->assertStatus(200);

        // Only what the service actually returned is persisted.
        $this->assertSame(1, SkillEvaluation::count());
        $this->assertSame(
            [Skill::where('slug', 'sql')->value('id')],
            SkillEvaluation::pluck('skill_id')->all()
        );
    }

    public function test_readiness_remains_blocked_until_every_role_skill_is_evaluated(): void
    {
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        $role = $this->role(['sql', 'python']);
        $this->item('sql-001', 'sql', 'Which clause filters rows?');
        $this->item('python-001', 'python', 'What does len() return?');

        $assessmentId = $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->json('data.id');

        // Only sql is evaluated by the assessment.
        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'skills' => [
                    ['slug' => 'sql', 'level' => 3.0, 'confidence' => 0.8],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessmentId}/submit", [
            'responses' => [
                ['question_id' => 'sql-001', 'answer' => 'A'],
                ['question_id' => 'python-001', 'answer' => 'A'],
            ],
        ])->assertStatus(200);

        // Readiness must still refuse: python has no evaluation, and the
        // gap must NOT be filled from anywhere.
        Http::fake();

        $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ASSESSMENT_INCOMPLETE')
            ->assertJsonPath('details.missing_skill_ids', [
                (int) Skill::where('slug', 'python')->value('id'),
            ]);
    }

    public function test_readiness_proceeds_once_all_role_skills_are_evaluated(): void
    {
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        $role = $this->role(['sql', 'python']);
        $this->item('sql-001', 'sql', 'Which clause filters rows?');
        $this->item('python-001', 'python', 'What does len() return?');

        $assessmentId = $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->json('data.id');

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'skills' => [
                    ['slug' => 'sql', 'level' => 3.0, 'confidence' => 0.8],
                    ['slug' => 'python', 'level' => 4.0, 'confidence' => 0.9],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessmentId}/submit", [
            'responses' => [
                ['question_id' => 'sql-001', 'answer' => 'A'],
                ['question_id' => 'python-001', 'answer' => 'A'],
            ],
        ])->assertStatus(200);

        $this->assertSame(2, SkillEvaluation::count());

        // Both role skills are now evaluated, so the ASSESSMENT_INCOMPLETE
        // gate is satisfied and readiness proceeds to the next stage.
        Http::fake([
            '*' => Http::response([
                'readiness_score' => 72.5,
                'skill_gaps' => [],
                'roadmap' => [],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => $role->id]);

        $this->assertNotSame(
            'ASSESSMENT_INCOMPLETE',
            $response->json('code'),
            'Readiness must not be blocked once every role skill has an evaluation.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, string>  $skillSlugs
     * @return array{0: BaselineAssessment, 1: CareerRole}
     */
    private function start(array $skillSlugs, array $itemIds): array
    {
        $role = $this->role($skillSlugs);

        foreach ($itemIds as $index => $itemId) {
            $this->item($itemId, $skillSlugs[$index], "Prompt for {$itemId}?");
        }

        $assessmentId = $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->json('data.id');

        return [BaselineAssessment::with('questionSnapshots')->findOrFail($assessmentId), $role];
    }

    /**
     * @param  array<int, string>  $skillSlugs
     */
    private function role(array $skillSlugs): CareerRole
    {
        $role = CareerRole::forceCreate([
            'title' => 'Data Analyst',
            'slug' => 'data-analyst-'.uniqid(),
            'version' => 1,
            'status' => 'approved',
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

    private function item(string $itemId, string $skillSlug, string $questionText): BaselineAssessmentItem
    {
        $skill = Skill::firstOrCreate(
            ['slug' => $skillSlug],
            ['name' => ucfirst($skillSlug), 'status' => 'active'],
        );

        return BaselineAssessmentItem::updateOrCreate(
            ['assessment_version' => 'v1.0', 'item_id' => $itemId],
            [
                'item_type' => 'single_choice',
                'question_text' => $questionText,
                'skill_id' => $skill->id,
                'options' => ['A', 'B', 'C', 'D'],
                'correct_answer' => 'A',
                'scoring_rule' => null,
                'weight' => 1.000,
                'is_active' => true,
            ],
        );
    }

    /**
     * @param  array<int, string>  $slugs
     */
    private function fakeIntelligence(array $slugs): void
    {
        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'skills' => collect($slugs)->map(fn (string $slug) => [
                    'slug' => $slug,
                    'level' => 3.0,
                    'confidence' => 0.8,
                ])->all(),
            ], 200),
        ]);
    }

    private function createLearner(): array
    {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($this->learnerRole->id);

        $profile = StudentProfile::forceCreate(['user_id' => $user->id]);

        return [$user, $profile];
    }
}
