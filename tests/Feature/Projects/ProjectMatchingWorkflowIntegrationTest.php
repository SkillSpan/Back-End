<?php

namespace Tests\Feature\Projects;

use App\Models\AlgorithmConfiguration;
use App\Models\Project;
use App\Models\ProjectMatchingSnapshot;
use App\Models\ProjectRequiredSkill;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Task 15 — full matching workflow integration.
 *
 * Exercises the whole chain in one place:
 *
 *   Laravel request → validated snapshot → (mocked) FastAPI
 *     → response validation → recommendation persistence → API response
 *     → retrieval / explanation read-back
 *
 * The FastAPI service is always mocked; no live service is required.
 */
class ProjectMatchingWorkflowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/projects/%d/match';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.data_science.url' => 'https://intelligence.test',
            'services.data_science.service_token' => 'test-service-token',
            'services.data_science.project_matching_enabled' => true,
            'services.data_science.project_matching_path' => '/api/v1/project-matching',
        ]);

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);

        AlgorithmConfiguration::create([
            'name' => 'project-matching',
            'version' => 1,
            'status' => 'active',
            'config' => [],
            'activated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------ fixtures

    private function createLearner(string $email = 'workflow@test.com'): User
    {
        $user = User::forceCreate([
            'name' => 'Workflow Learner',
            'email' => $email,
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);

        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'visibility' => 'private',
            'consent_given' => true,
            'availability' => 'full_time',
            'preferred_work_type' => 'remote',
        ]);

        return $user->load('studentProfile');
    }

    private function createProject(array $attributes = []): Project
    {
        return Project::create(array_merge([
            'organization_id' => null,
            'owner_id' => User::first()?->id ?? User::forceCreate([
                'name' => 'Project Owner',
                'email' => 'workflow-owner@test.com',
                'password' => 'password123',
                'status' => 'active',
                'email_verified_at' => now(),
            ])->id,
            'title' => 'Workflow Project',
            'description' => 'Integration project',
            'type' => 'company_sponsored',
            'domain' => 'backend',
            'work_mode' => 'remote',
            'role' => 'Backend Developer',
            'schedule' => 'part_time',
            'status' => 'open',
            'confidentiality' => 'public',
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
            'version' => 1,
        ], $attributes));
    }

    /**
     * A fake that echoes the identifiers it received back, so correlation is
     * proven by the assertions rather than assumed.
     */
    private function fakeFastApi(float $score = 74.0): void
    {
        Http::fake(function ($request) use ($score) {
            $body = $request->data();

            return Http::response([
                'request_id' => $body['request_id'],
                'algorithm_version' => $body['algorithm_version'],
                'configuration_version' => $body['configuration_version'],
                'recommendation' => [
                    'project_id' => $body['project']['id'],
                    'project_version' => $body['project_version'],
                    'eligibility_state' => 'eligible',
                    'matching_state' => 'scored',
                    'score' => $score,
                    'factor_scores' => [
                        'skill_compatibility' => 80.0,
                        'learning_value' => 70.0,
                        'career_relevance' => 75.0,
                        'interest_match' => 60.0,
                        'availability_fit' => 90.0,
                    ],
                    'weighted_contributions' => [
                        'skill_compatibility' => 40.0,
                        'learning_value' => 14.0,
                        'career_relevance' => 11.25,
                        'interest_match' => 3.0,
                        'availability_fit' => 4.5,
                    ],
                    'explanation' => ['Aligned with the learner skill profile.'],
                    'limiting_factors' => ['Limited schedule overlap.'],
                    'skill_results' => [],
                ],
            ], 200);
        });
    }

    // ---------------------------------------------- 1. happy-path full chain

    public function test_full_chain_matching_persists_and_is_readable_end_to_end(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $this->fakeFastApi(74.0);
        $project = $this->createProject();

        // 1-2. Match request → snapshot → FastAPI → validation → response.
        $match = $this->postJson(sprintf(self::ENDPOINT, $project->id), [], [
            'X-Request-ID' => 'workflow-happy',
        ]);

        $match->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.algorithm_version', 'project-matching-v1')
            ->assertJsonPath('data.configuration_version', 'project-matching-config-v1');

        // The validated snapshot was captured.
        $this->assertDatabaseHas('project_matching_snapshots', [
            'project_id' => $project->id,
            'student_profile_id' => $learner->studentProfile->id,
            'status' => 'validated',
        ]);

        // 3. The result was persisted against the learner.
        $this->assertDatabaseHas('recommendations', [
            'user_id' => $learner->id,
            'candidate_id' => $project->id,
            'type' => 'project',
            'algorithm_version' => 'project-matching-v1',
            'configuration_version' => 'project-matching-config-v1',
        ]);

        // 4. It is readable through the Task 11 list endpoint.
        $this->getJson('/api/v1/recommendations')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.project_id', $project->id);

        // 5. And through the Task 12 explanation endpoint.
        $this->getJson('/api/v1/projects/'.$project->id.'/recommendation')
            ->assertStatus(200)
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.reasons', 'Aligned with the learner skill profile.')
            ->assertJsonPath('data.limiting_factors.0', 'Limited schedule overlap.')
            ->assertJsonPath('data.algorithm_version', 'project-matching-v1');
    }

    // ------------------------------------------------- 6. determinism

    public function test_identical_inputs_produce_an_identical_persisted_result(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $this->fakeFastApi(61.5);
        $project = $this->createProject();

        $first = $this->postJson(sprintf(self::ENDPOINT, $project->id))->assertStatus(200);
        $second = $this->postJson(sprintf(self::ENDPOINT, $project->id))->assertStatus(200);

        $this->assertEquals($first->json('data.score'), $second->json('data.score'));
        $this->assertSame(
            $first->json('data.algorithm_version'),
            $second->json('data.algorithm_version'),
        );
        $this->assertSame(
            $first->json('data.configuration_version'),
            $second->json('data.configuration_version'),
        );

        // 12. Repeated requests must not create duplicate recommendations.
        $this->assertDatabaseCount('recommendations', 1);
    }

    public function test_repeated_matching_across_many_requests_stays_a_single_recommendation(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $this->fakeFastApi(58.0);
        $project = $this->createProject();

        foreach (range(1, 5) as $ignored) {
            $this->postJson(sprintf(self::ENDPOINT, $project->id))->assertStatus(200);
        }

        $this->assertDatabaseCount('recommendations', 1);
        // Task 8 snapshots stay append-only — one per request.
        $this->assertDatabaseCount('project_matching_snapshots', 5);
    }

    // ------------------------------------------- 2. timeout / transport errors

    public function test_fastapi_timeout_is_handled_safely_without_persisting(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $project = $this->createProject();

        $this->postJson(sprintf(self::ENDPOINT, $project->id))
            ->assertStatus(503)
            ->assertJsonPath('code', 'INTELLIGENCE_UNAVAILABLE');

        // 11. No false success, and nothing persisted.
        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_upstream_server_error_is_handled_safely(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        Http::fake(['*' => Http::response(['detail' => 'boom'], 500)]);

        $project = $this->createProject();

        $this->postJson(sprintf(self::ENDPOINT, $project->id))
            ->assertStatus(503)
            ->assertJsonPath('code', 'INTELLIGENCE_UNAVAILABLE');

        $this->assertDatabaseCount('recommendations', 0);
    }

    // ------------------------------------------------- 3. FastAPI HTTP 422

    public function test_fastapi_422_is_surfaced_and_persists_nothing(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        Http::fake(['*' => Http::response(['detail' => 'invalid payload'], 422)]);

        $project = $this->createProject();

        $this->postJson(sprintf(self::ENDPOINT, $project->id))
            ->assertStatus(422)
            ->assertJsonPath('code', 'INTELLIGENCE_VALIDATION_ERROR');

        $this->assertDatabaseCount('recommendations', 0);
    }

    // ------------------------------------- 4-5. invalid / incomplete responses

    public function test_invalid_response_structure_is_rejected_without_persisting(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        Http::fake(['*' => Http::response('not-json{', 200)]);

        $project = $this->createProject();

        $this->postJson(sprintf(self::ENDPOINT, $project->id))
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_empty_response_body_is_rejected_without_persisting(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        Http::fake(['*' => Http::response([], 200)]);

        $project = $this->createProject();

        $this->postJson(sprintf(self::ENDPOINT, $project->id))
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_incomplete_response_is_rejected_without_persisting(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        Http::fake(function ($request) {
            $body = $request->data();

            // request_id and versions echo correctly, but the recommendation
            // block is missing entirely.
            return Http::response([
                'request_id' => $body['request_id'],
                'algorithm_version' => $body['algorithm_version'],
                'configuration_version' => $body['configuration_version'],
            ], 200);
        });

        $project = $this->createProject();

        $this->postJson(sprintf(self::ENDPOINT, $project->id))
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_mismatched_version_echo_is_rejected_without_persisting(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        Http::fake(function ($request) {
            $body = $request->data();

            return Http::response([
                'request_id' => $body['request_id'],
                'algorithm_version' => 'project-matching-v9',
                'configuration_version' => $body['configuration_version'],
                'recommendation' => [
                    'project_id' => $body['project']['id'],
                    'project_version' => $body['project_version'],
                    'eligibility_state' => 'eligible',
                    'matching_state' => 'scored',
                    'score' => 50.0,
                    'factor_scores' => [
                        'skill_compatibility' => 50.0,
                        'learning_value' => 50.0,
                        'career_relevance' => 50.0,
                        'interest_match' => 50.0,
                        'availability_fit' => 50.0,
                    ],
                    'weighted_contributions' => [
                        'skill_compatibility' => 25.0,
                        'learning_value' => 10.0,
                        'career_relevance' => 7.5,
                        'interest_match' => 2.5,
                        'availability_fit' => 2.5,
                    ],
                    'explanation' => [],
                    'limiting_factors' => [],
                    'skill_results' => [],
                ],
            ], 200);
        });

        $project = $this->createProject();

        $this->postJson(sprintf(self::ENDPOINT, $project->id))
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_RESPONSE_MISMATCH');

        $this->assertDatabaseCount('recommendations', 0);
    }

    // --------------------------------------------- 8-9. persistence behaviour

    public function test_persistence_failure_produces_an_error_and_no_recommendation(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $this->fakeFastApi();

        Recommendation::creating(function () {
            throw new \RuntimeException('simulated persistence failure');
        });

        $project = $this->createProject();

        // 11. The failure must not be reported as a successful recommendation.
        $response = $this->postJson(sprintf(self::ENDPOINT, $project->id));

        $response->assertStatus(500)
            ->assertJsonPath('code', 'PROJECT_MATCHING_FAILED');

        $this->assertDatabaseCount('recommendations', 0);
    }

    // ------------------------------------------------------ 7. version handling

    public function test_versions_come_from_the_snapshot_not_from_the_response(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $this->fakeFastApi(70.0);
        $project = $this->createProject();

        $this->postJson(sprintf(self::ENDPOINT, $project->id))->assertStatus(200);

        $snapshot = ProjectMatchingSnapshot::where('project_id', $project->id)->firstOrFail();
        $recommendation = Recommendation::where('candidate_id', $project->id)->firstOrFail();

        $this->assertSame('project-matching-v1', $snapshot->algorithm_version);
        $this->assertSame('project-matching-config-v1', $snapshot->configuration_version);
        $this->assertSame($snapshot->algorithm_version, $recommendation->algorithm_version);
        $this->assertSame($snapshot->configuration_version, $recommendation->configuration_version);
        $this->assertSame($snapshot->project_version, $recommendation->project_version);
    }

    // ------------------------------------------- 10. eligibility gate on the chain

    public function test_ineligible_learner_is_rejected_before_fastapi_and_persists_nothing(): void
    {
        // Task 14 requirement: matching must not create a recommendation for an
        // ineligible learner. Eligibility is enforced in the MATCHING flow
        // (ProjectMatchingSnapshotService), not in project discovery.
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $this->fakeFastApi();

        $project = $this->createProject();

        $skill = Skill::create(['name' => 'Rust', 'slug' => 'rust', 'category' => 'backend']);

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill->id,
            'minimum_level' => 4.0,
            'is_critical_entry' => true,
        ]);

        // The learner has no Rust evaluation at all.
        $this->postJson(sprintf(self::ENDPOINT, $project->id))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_MATCH_INELIGIBLE');

        $this->assertDatabaseCount('recommendations', 0);
        // The ineligible learner must be rejected before any upstream call.
        Http::assertNothingSent();
    }

    // ------------------------------------------------- 10. isolation on the chain

    public function test_two_learners_matching_the_same_project_get_separate_recommendations(): void
    {
        $first = $this->createLearner('first-workflow@test.com');
        $second = $this->createLearner('second-workflow@test.com');

        $project = $this->createProject();
        $this->fakeFastApi(64.0);

        Sanctum::actingAs($first);
        $this->postJson(sprintf(self::ENDPOINT, $project->id))->assertStatus(200);

        Sanctum::actingAs($second);
        $this->postJson(sprintf(self::ENDPOINT, $project->id))->assertStatus(200);

        $this->assertDatabaseCount('recommendations', 2);

        // Each learner reads back only their own.
        Sanctum::actingAs($first);
        $this->getJson('/api/v1/recommendations')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/projects/'.$project->id.'/recommendation')->assertStatus(200);

        Sanctum::actingAs($second);
        $this->getJson('/api/v1/recommendations')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/projects/'.$project->id.'/recommendation')->assertStatus(200);
    }
}
