<?php

namespace Tests\Feature\Assessment;

use App\Models\BaselineAssessment;
use App\Models\BaselineAssessmentItem;
use App\Models\CareerRole;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Task B — Data Science request/response contract, pinned by test.
 *
 * The Data Science service currently has NO baseline endpoint (see
 * DATASCIENCE_BASELINE_CONTRACT_VERIFICATION.md). These tests therefore
 * pin the LARAVEL side of the contract: exactly what is sent, and exactly
 * what is accepted back. They are the executable specification the Data
 * Science team must implement against.
 */
class BaselineDataScienceContractTest extends TestCase
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
    | Outbound request shape
    |--------------------------------------------------------------------------
    */

    public function test_request_contains_exactly_the_documented_fields(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment, $role] = $this->start(['sql', 'python'], ['sql-001', 'python-001']);

        $this->fakeIntelligence(['sql', 'python']);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['question_id' => 'sql-001', 'answer' => 'A'],
                ['question_id' => 'python-001', 'answer' => 'A'],
            ],
        ])->assertStatus(200);

        Http::assertSent(function ($request) use ($assessment, $role) {
            $payload = $request->data();

            // Required core fields.
            $this->assertSame((int) $assessment->student_profile_id, $payload['student_profile_id']);
            $this->assertSame((int) $assessment->studentProfile->user_id, $payload['user_id']);
            $this->assertSame('v1.0', $payload['assessment_version']);
            $this->assertSame((int) $role->id, $payload['career_role_id']);
            $this->assertSame((int) $role->version, $payload['career_role_version']);

            // Identifier namespace: item_id strings, not numeric keys.
            $this->assertEqualsCanonicalizing(
                ['sql-001', 'python-001'],
                $payload['question_ids']
            );

            // Responses carry the same namespace.
            $this->assertEqualsCanonicalizing(
                ['sql-001', 'python-001'],
                collect($payload['responses'])->pluck('question_id')->all()
            );

            // Fields that must NOT be sent (documented decision, §3).
            $this->assertArrayNotHasKey('questions', $payload);
            $this->assertArrayNotHasKey('skill_mappings', $payload);

            // Auth + tracing headers.
            $this->assertSame('Bearer test-service-token', $request->header('Authorization')[0] ?? null);
            $this->assertNotEmpty($request->header('X-Request-ID'));

            return true;
        });
    }

    public function test_question_ids_are_never_numeric_primary_keys(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001']);
        $this->fakeIntelligence(['sql']);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])->assertStatus(200);

        Http::assertSent(function ($request) {
            foreach ($request->data()['question_ids'] as $id) {
                $this->assertIsString($id);
                $this->assertFalse(ctype_digit($id), "question_ids must not be numeric: {$id}");
            }

            return true;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Inbound response shape
    |--------------------------------------------------------------------------
    */

    public function test_skills_key_is_required_and_skill_evaluations_is_not_accepted(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001']);
        $sql = Skill::where('slug', 'sql')->firstOrFail();

        // The Data Science service naming the array `skill_evaluations`
        // instead of `skills` must NOT be silently accepted.
        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'skill_evaluations' => [
                    ['slug' => $sql->slug, 'level' => 3.0, 'confidence' => 0.8],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        // Nothing persisted.
        $this->assertSame(0, SkillEvaluation::count());
        $this->assertSame('in_progress', $assessment->fresh()->status);
    }

    public function test_confidence_may_be_omitted_and_defaults_safely(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001']);
        $sql = Skill::where('slug', 'sql')->firstOrFail();

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'skills' => [
                    ['slug' => $sql->slug, 'level' => 2.5],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])->assertStatus(200);

        $evaluation = SkillEvaluation::where('skill_id', $sql->id)->firstOrFail();

        $this->assertSame(2.5, (float) $evaluation->level);
    }

    public function test_extra_top_level_fields_are_ignored_not_rejected(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001']);
        $sql = Skill::where('slug', 'sql')->firstOrFail();

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'student_profile_id' => 999,
                'overall_score' => 61,
                'skills' => [
                    ['slug' => $sql->slug, 'level' => 3.0, 'confidence' => 0.8],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])->assertStatus(200);
    }

    public function test_algorithm_version_is_persisted_on_every_evaluation(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql', 'python'], ['sql-001', 'python-001']);

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v9.9.9',
                'skills' => [
                    ['slug' => 'sql', 'level' => 3.0, 'confidence' => 0.8],
                    ['slug' => 'python', 'level' => 4.0, 'confidence' => 0.9],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['question_id' => 'sql-001', 'answer' => 'A'],
                ['question_id' => 'python-001', 'answer' => 'A'],
            ],
        ])->assertStatus(200);

        $this->assertSame(2, SkillEvaluation::count());
        $this->assertSame(
            ['baseline-v9.9.9'],
            SkillEvaluation::query()->pluck('algorithm_version')->unique()->values()->all()
        );
    }

    public function test_evaluation_ownership_comes_from_the_assessment_not_the_service_response(): void
    {
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql'], ['sql-001']);
        $sql = Skill::where('slug', 'sql')->firstOrFail();

        // A hostile/incorrect service tries to attribute the evaluation to
        // a different learner.
        $victimProfileId = $profile->id + 9999;
        $victimUserId = $user->id + 9999;

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'student_profile_id' => $victimProfileId,
                'user_id' => $victimUserId,
                'skills' => [
                    ['slug' => $sql->slug, 'level' => 3.0, 'confidence' => 0.8],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [['question_id' => 'sql-001', 'answer' => 'A']],
        ])->assertStatus(200);

        $evaluation = SkillEvaluation::firstOrFail();

        // The evaluation belongs to the authenticated learner, never to
        // whatever id the service echoed back.
        $this->assertSame((int) $profile->id, (int) $evaluation->student_profile_id);
        $this->assertNotSame($victimProfileId, (int) $evaluation->student_profile_id);
    }

    /**
     * STEP 18 — a failing upstream response must not put its body in our log.
     *
     * The body belongs to another service, so it is untrusted text: on a 5xx it
     * can be a debug page or a traceback that echoes the request it received —
     * which here is the learner's own record.
     */
    public function test_a_5xx_does_not_copy_the_upstream_body_into_the_log(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql', 'python'], ['sql-001', 'python-001']);

        Http::fake([
            '*/api/v1/baseline' => Http::response(
                "Traceback (most recent call last):\n  payload = {'email': 'LEAK-PROBE-VALUE-9f3a'}",
                500,
            ),
        ]);

        Log::spy();

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['question_id' => 'sql-001', 'answer' => 'A'],
                ['question_id' => 'python-001', 'answer' => 'A'],
            ],
        ])->assertStatus(503);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'Baseline intelligence request failed')
                && ! str_contains($context['failure_reason'] ?? '', 'LEAK-PROBE-VALUE-9f3a')
                && str_contains($context['failure_reason'] ?? '', 'upstream body omitted'))
            ->once();
    }

    /**
     * The counterpart: the documented `{"detail": "..."}` explanation IS kept,
     * because that string is what actually diagnoses the failure.
     */
    public function test_a_5xx_keeps_the_documented_detail_message_in_the_log(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        [$assessment] = $this->start(['sql', 'python'], ['sql-001', 'python-001']);

        Http::fake([
            '*/api/v1/baseline' => Http::response(['detail' => 'unknown assessment_version'], 503),
        ]);

        Log::spy();

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['question_id' => 'sql-001', 'answer' => 'A'],
                ['question_id' => 'python-001', 'answer' => 'A'],
            ],
        ])->assertStatus(503);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'Baseline intelligence request failed')
                && str_contains($context['failure_reason'] ?? '', 'unknown assessment_version'))
            ->once();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, string>  $skillSlugs
     * @param  array<int, string>  $itemIds
     * @return array{0: BaselineAssessment, 1: CareerRole}
     */
    private function start(array $skillSlugs, array $itemIds): array
    {
        $role = CareerRole::create([
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

            BaselineAssessmentItem::updateOrCreate(
                ['assessment_version' => 'v1.0', 'item_id' => $itemIds[$index]],
                [
                    'item_type' => 'single_choice',
                    'question_text' => "Baseline prompt for {$itemIds[$index]}.",
                    'skill_id' => $skill->id,
                    'options' => ['A', 'B', 'C', 'D'],
                    'correct_answer' => 'A',
                    'scoring_rule' => null,
                    'weight' => 1.000,
                    'is_active' => true,
                ],
            );
        }

        $assessmentId = $this->postJson('/api/v1/baseline-assessments', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->json('data.id');

        return [BaselineAssessment::with('questionSnapshots')->findOrFail($assessmentId), $role];
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
