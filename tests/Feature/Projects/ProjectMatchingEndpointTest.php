<?php

namespace Tests\Feature\Projects;

use App\Models\AlgorithmConfiguration;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Task 10 — POST /api/v1/projects/{project}/match (HTTP surface).
 *
 * Covers the route wiring, auth/role middleware, the error envelope, and
 * a full end-to-end success path where the FastAPI fake echoes the
 * request identifiers back, proving correlation survives the round trip.
 */
class ProjectMatchingEndpointTest extends TestCase
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
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);

        AlgorithmConfiguration::create([
            'name' => 'project-matching',
            'version' => 1,
            'status' => 'active',
            'config' => [],
            'activated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------ fixtures

    private function createLearner(bool $withProfile = true): User
    {
        $user = User::forceCreate([
            'name' => 'Test Learner',
            'email' => 'learner@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);

        if ($withProfile) {
            StudentProfile::forceCreate([
                'user_id' => $user->id,
                'visibility' => 'private',
                'consent_given' => true,
                'availability' => 'full_time',
                'preferred_work_type' => 'remote',
            ]);
        }

        return $user->load('studentProfile');
    }

    private function createProject(array $attributes = []): Project
    {
        return Project::create(array_merge([
            'organization_id' => null,
            'owner_id' => User::first()?->id ?? User::forceCreate([
                'name' => 'Project Owner',
                'email' => 'owner@test.com',
                'password' => 'password123',
                'status' => 'active',
                'email_verified_at' => now(),
            ])->id,
            'title' => 'Test Project',
            'description' => 'A test project',
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
     * A fake that echoes the identifiers it received, so a passing
     * assertion proves request_id / project_id / project_version
     * correlation survived the round trip.
     */
    private function fakeEchoingService(): void
    {
        Http::fake(function ($request) {
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
                    'score' => 81.0,
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
                    'explanation' => ['Strong alignment with the project skill set.'],
                    'limiting_factors' => [],
                    'skill_results' => [],
                ],
            ], 200);
        });
    }

    // -------------------------------------------------------- auth / role

    public function test_unauthenticated_request_is_rejected(): void
    {
        $project = $this->createProject();

        $this->postJson(sprintf(self::ENDPOINT, $project->id))
            ->assertStatus(401);
    }

    public function test_non_learner_is_rejected(): void
    {
        $admin = User::forceCreate([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $admin->roles()->attach(Role::where('slug', 'admin')->first()->id);

        Sanctum::actingAs($admin);

        $project = $this->createProject();

        $this->postJson(sprintf(self::ENDPOINT, $project->id))
            ->assertStatus(403)
            ->assertJsonPath('code', 'LEARNER_ONLY');
    }

    // -------------------------------------------------------- route guards

    public function test_unknown_project_returns_not_found(): void
    {
        Sanctum::actingAs($this->createLearner());

        $this->postJson(sprintf(self::ENDPOINT, 999999))
            ->assertStatus(404)
            ->assertJsonPath('code', 'PROJECT_NOT_FOUND')
            ->assertJsonPath('details.project_id', 999999);
    }

    public function test_non_numeric_project_id_does_not_match_the_route(): void
    {
        Sanctum::actingAs($this->createLearner());

        // whereNumber('project') means this never reaches the controller.
        $this->postJson('/api/v1/projects/not-a-number/match')
            ->assertStatus(404);
    }

    // -------------------------------------------------------- domain errors

    public function test_learner_without_student_profile_is_rejected(): void
    {
        Sanctum::actingAs($this->createLearner(withProfile: false));

        $project = $this->createProject();

        $this->postJson(sprintf(self::ENDPOINT, $project->id))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_MATCH_NO_STUDENT_PROFILE');
    }

    public function test_unavailable_project_is_rejected(): void
    {
        Sanctum::actingAs($this->createLearner());

        $project = $this->createProject(['status' => 'closed']);

        $this->postJson(sprintf(self::ENDPOINT, $project->id))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_MATCH_UNAVAILABLE');
    }

    public function test_restricted_project_from_another_organization_is_rejected(): void
    {
        $ownOrg = Organization::create(['name' => 'Own Org', 'type' => 'company']);
        $otherOrg = Organization::create(['name' => 'Other Org', 'type' => 'company']);

        $learner = $this->createLearner();
        $learner->organizations()->attach($ownOrg->id, ['role_in_org' => 'member', 'status' => 'active']);

        Sanctum::actingAs($learner);

        $project = $this->createProject([
            'organization_id' => $otherOrg->id,
            'confidentiality' => 'restricted',
        ]);

        $this->postJson(sprintf(self::ENDPOINT, $project->id))
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_MATCH_UNAUTHORIZED');
    }

    public function test_disabled_integration_returns_not_configured(): void
    {
        config(['services.data_science.project_matching_enabled' => false]);

        Sanctum::actingAs($this->createLearner());

        Http::fake();

        $project = $this->createProject();

        $this->postJson(sprintf(self::ENDPOINT, $project->id))
            ->assertStatus(503)
            ->assertJsonPath('code', 'INTELLIGENCE_NOT_CONFIGURED');

        Http::assertNothingSent();
    }

    public function test_upstream_failure_is_surfaced_with_its_own_code(): void
    {
        Sanctum::actingAs($this->createLearner());

        Http::fake(['*' => Http::response(['detail' => 'invalid payload'], 422)]);

        $project = $this->createProject();

        $this->postJson(sprintf(self::ENDPOINT, $project->id))
            ->assertStatus(422)
            ->assertJsonPath('code', 'INTELLIGENCE_VALIDATION_ERROR');
    }

    // ----------------------------------------------------------- happy path

    public function test_learner_receives_a_matching_recommendation(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $this->fakeEchoingService();

        $project = $this->createProject();

        $response = $this->postJson(sprintf(self::ENDPOINT, $project->id), [], [
            'X-Request-ID' => 'caller-supplied-request-id',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('request_id', 'caller-supplied-request-id')
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.project_version', $project->version)
            ->assertJsonPath('data.eligibility_state', 'eligible')
            ->assertJsonPath('data.matching_state', 'scored')
            ->assertJsonPath('data.algorithm_version', 'project-matching-v1')
            ->assertJsonPath('data.configuration_version', 'project-matching-config-v1');

        // Loose comparison: JSON may encode 81.0 as either 81 or 81.0.
        $this->assertEquals(81.0, $response->json('data.score'));

        // The caller's correlation id is echoed back on the response.
        $this->assertSame('caller-supplied-request-id', $response->headers->get('X-Request-ID'));
    }

    public function test_a_validated_snapshot_is_persisted_for_the_call(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $this->fakeEchoingService();

        $project = $this->createProject();

        $this->postJson(sprintf(self::ENDPOINT, $project->id))->assertStatus(200);

        $this->assertDatabaseHas('project_matching_snapshots', [
            'project_id' => $project->id,
            'student_profile_id' => $learner->studentProfile->id,
            'status' => 'validated',
            'algorithm_version' => 'project-matching-v1',
            'configuration_version' => 'project-matching-config-v1',
        ]);
    }

    public function test_error_envelope_preserves_the_caller_request_id(): void
    {
        config(['services.data_science.project_matching_enabled' => false]);

        Sanctum::actingAs($this->createLearner());

        Http::fake();

        $project = $this->createProject(['status' => 'closed']);

        $response = $this->postJson(sprintf(self::ENDPOINT, $project->id), [], [
            'X-Request-ID' => 'trace-me-123',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('request_id', 'trace-me-123');

        $this->assertSame('trace-me-123', $response->headers->get('X-Request-ID'));
    }
}
