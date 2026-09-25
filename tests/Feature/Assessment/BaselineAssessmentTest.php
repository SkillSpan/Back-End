<?php

namespace Tests\Feature\Assessment;

use App\Models\BaselineAssessment;
use App\Models\Role;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

        // US-INT-01: the service credential is mandatory on every
        // Laravel -> FastAPI intelligence request, baseline included.
        Config::set('services.data_science.service_token', 'test-service-token');
    }

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

    public function test_start_creates_in_progress_assessment_with_server_version(): void
    {
        Config::set('services.data_science.baseline.version', 'v1.0');
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/baseline-assessments');

        $response->assertStatus(201)
            ->assertJsonPath('data.assessment_type', 'baseline')
            ->assertJsonPath('data.assessment_version', 'v1.0')
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertDatabaseHas('baseline_assessments', [
            'student_profile_id' => $profile->id,
            'assessment_type' => 'baseline',
            'assessment_version' => 'v1.0',
            'status' => 'in_progress',
        ]);
    }

    public function test_start_ignores_client_supplied_version(): void
    {
        Config::set('services.data_science.baseline.version', 'v1.0');
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/baseline-assessments', ['assessment_version' => 'v999'])
            ->assertStatus(201)
            ->assertJsonPath('data.assessment_version', 'v1.0');
    }

    public function test_start_rejects_second_attempt_for_same_version(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/baseline-assessments')->assertStatus(201);

        $this->postJson('/api/v1/baseline-assessments')
            ->assertStatus(409)
            ->assertJsonPath('code', 'ASSESSMENT_ALREADY_EXISTS');
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

    public function test_submit_uses_intelligence_service_result(): void
    {
        [$user, $profile] = $this->createLearner();
        Sanctum::actingAs($user);

        $python = Skill::forceCreate(['name' => 'Python', 'slug' => 'python', 'status' => 'active']);
        $sql = Skill::forceCreate(['name' => 'SQL', 'slug' => 'sql', 'status' => 'active']);

        $assessment = BaselineAssessment::forceCreate([
            'student_profile_id' => $profile->id,
            'assessment_type' => 'baseline',
            'assessment_version' => 'v1.0',
            'status' => 'in_progress',
        ]);

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

        $response = $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['item_id' => 'q1', 'answer' => 'python-3'],
                ['item_id' => 'q2', 'answer' => 'sql-2'],
            ],
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

        $this->assertDatabaseHas('skill_evidences', [
            'student_profile_id' => $profile->id,
            'skill_id' => $sql->id,
            'source' => 'assessment_test',
            'normalized_value' => 2.50,
        ]);

        $this->assertDatabaseHas('skill_evaluations', [
            'student_profile_id' => $profile->id,
            'skill_id' => $python->id,
            'level' => 3.00,
            // Normalized from 0.7 to the 0-100 confidence scale.
            'confidence' => 70.00,
            'algorithm_version' => 'baseline-v1.0',
        ]);
    }

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

    public function test_submit_rejects_an_object_shaped_responses_payload(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $assessment = BaselineAssessment::forceCreate([
            'student_profile_id' => $this->learnerProfile($user)->id,
            'assessment_type' => 'baseline',
            'assessment_version' => 'v1.0',
            'status' => 'in_progress',
        ]);

        /*
         * A PHP associative array satisfies `array` and then json_encodes to
         * the OBJECT {"q1":"a"}. The FastAPI contract requires an ARRAY of
         * {item_id, answer} objects, so this must be rejected here rather
         * than surfacing as an opaque upstream 422.
         */
        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => ['q1' => 'a'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['responses']);
    }

    public function test_submit_rejects_responses_missing_item_id_or_answer(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $assessment = BaselineAssessment::forceCreate([
            'student_profile_id' => $this->learnerProfile($user)->id,
            'assessment_type' => 'baseline',
            'assessment_version' => 'v1.0',
            'status' => 'in_progress',
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['answer' => 'B'],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['responses.0.item_id']);
    }

    public function test_submit_fails_safely_when_intelligence_not_configured(): void
    {
        Config::set('services.data_science.baseline.enabled', false);
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $assessment = BaselineAssessment::forceCreate([
            'student_profile_id' => $this->learnerProfile($user)->id,
            'assessment_type' => 'baseline',
            'assessment_version' => 'v1.0',
            'status' => 'in_progress',
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['item_id' => 'sql-001', 'answer' => 'B'],
            ],
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

        $assessment = BaselineAssessment::forceCreate([
            'student_profile_id' => $this->learnerProfile($user)->id,
            'assessment_type' => 'baseline',
            'assessment_version' => 'v1.0',
            'status' => 'in_progress',
        ]);

        Http::fake([
            '*/api/v1/baseline' => Http::response('', 503),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['item_id' => 'sql-001', 'answer' => 'B'],
            ],
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'INTELLIGENCE_SERVICE_ERROR');

        $this->assertDatabaseHas('baseline_assessments', [
            'id' => $assessment->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_upstream_5xx_body_is_logged_so_the_cause_is_not_hidden(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $assessment = BaselineAssessment::forceCreate([
            'student_profile_id' => $this->learnerProfile($user)->id,
            'assessment_type' => 'baseline',
            'assessment_version' => 'v1.0',
            'status' => 'in_progress',
        ]);

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'detail' => 'Data Science service authentication is not configured.',
            ], 503),
        ]);

        Log::spy();

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['item_id' => 'sql-001', 'answer' => 'B'],
            ],
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'INTELLIGENCE_SERVICE_ERROR');

        // Regression guard: the upstream body used to be thrown away, which
        // left the generic 503 with no way to distinguish a service-side
        // configuration failure from a genuine server crash.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => $message === 'Baseline intelligence request failed.'
                && ($context['http_status'] ?? null) === 503
                && str_contains($context['failure_reason'] ?? '', 'authentication is not configured')
                && ! empty($context['request_id']))
            ->once();

        // Still no partial write: a failed submission must not complete.
        $this->assertDatabaseHas('baseline_assessments', [
            'id' => $assessment->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_submit_rejects_unknown_skill_reference_from_intelligence(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);

        $assessment = BaselineAssessment::forceCreate([
            'student_profile_id' => $this->learnerProfile($user)->id,
            'assessment_type' => 'baseline',
            'assessment_version' => 'v1.0',
            'status' => 'in_progress',
        ]);

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'skills' => [
                    ['slug' => 'does-not-exist', 'level' => 3.0, 'confidence' => 0.8],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['item_id' => 'sql-001', 'answer' => 'B'],
            ],
        ])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');
    }

    public function test_submit_rejects_out_of_range_level_from_intelligence(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);
        $python = Skill::forceCreate(['name' => 'Python', 'slug' => 'python', 'status' => 'active']);

        $assessment = BaselineAssessment::forceCreate([
            'student_profile_id' => $this->learnerProfile($user)->id,
            'assessment_type' => 'baseline',
            'assessment_version' => 'v1.0',
            'status' => 'in_progress',
        ]);

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'skills' => [
                    ['slug' => $python->slug, 'level' => 8.0, 'confidence' => 0.8],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", [
            'responses' => [
                ['item_id' => 'sql-001', 'answer' => 'B'],
            ],
        ])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');
    }

    public function test_duplicate_submit_is_rejected(): void
    {
        [$user] = $this->createLearner();
        Sanctum::actingAs($user);
        $python = Skill::forceCreate(['name' => 'Python', 'slug' => 'python', 'status' => 'active']);

        $assessment = BaselineAssessment::forceCreate([
            'student_profile_id' => $this->learnerProfile($user)->id,
            'assessment_type' => 'baseline',
            'assessment_version' => 'v1.0',
            'status' => 'in_progress',
        ]);

        Http::fake([
            '*/api/v1/baseline' => Http::response([
                'algorithm_version' => 'baseline-v1.0',
                'skills' => [
                    ['slug' => $python->slug, 'level' => 3.0, 'confidence' => 0.8],
                ],
            ], 200),
        ]);

        $payload = ['responses' => [['item_id' => 'sql-001', 'answer' => 'B']]];

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", $payload)
            ->assertStatus(200);

        $this->postJson("/api/v1/baseline-assessments/{$assessment->id}/submit", $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'ASSESSMENT_ALREADY_COMPLETED');
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
