<?php

namespace Tests\Feature\Projects;

use App\Exceptions\IntelligenceException;
use App\Models\AlgorithmConfiguration;
use App\Models\Project;
use App\Models\ProjectMatchingSnapshot;
use App\Models\ProjectRequiredSkill;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Projects\ProjectMatchingService;
use App\Services\Projects\ProjectMatchingSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Task 10 — ProjectMatchingService contract integration.
 *
 * Exercises the Laravel -> FastAPI POST /api/v1/project-matching call
 * against the verified ProjectMatchingRequest / ProjectMatchingResponse
 * schema: payload shape, auth headers, correlation, schema validation,
 * and the full error taxonomy. No live service is contacted.
 */
class ProjectMatchingServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProjectMatchingService $service;

    private ProjectMatchingSnapshotService $snapshotService;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.data_science.url' => 'https://intelligence.test',
            'services.data_science.service_token' => 'test-service-token',
            'services.data_science.project_matching_enabled' => true,
            'services.data_science.project_matching_path' => '/api/v1/project-matching',
        ]);

        $this->service = new ProjectMatchingService;
        $this->snapshotService = new ProjectMatchingSnapshotService;

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

    private function createLearner(): User
    {
        $user = User::forceCreate([
            'name' => 'Test Learner',
            'email' => 'learner@test.com',
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
     * A project with one NON-critical required skill, so eligibility
     * passes on skill grounds regardless of the learner's level, while
     * still producing a meaningful required_skills block.
     *
     * @return array{0: Project, 1: Skill}
     */
    private function createProjectWithSkill(): array
    {
        $skill = Skill::create(['name' => 'PHP', 'slug' => 'php', 'category' => 'backend']);

        $project = $this->createProject();

        ProjectRequiredSkill::create([
            'project_id' => $project->id,
            'skill_id' => $skill->id,
            'minimum_level' => 3.0,
            'is_critical_entry' => false,
        ]);

        return [$project, $skill];
    }

    private function makeSnapshot(?User $learner = null, ?Project $project = null): ProjectMatchingSnapshot
    {
        $learner ??= $this->createLearner();
        $project ??= $this->createProject();

        $project->load(['requiredSkills.skill', 'eligibilityConstraints']);

        return $this->snapshotService->createForProject($project, $learner);
    }

    /**
     * A response body that satisfies the verified ProjectMatchingResponse
     * schema for the given snapshot.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validResponse(ProjectMatchingSnapshot $snapshot, array $overrides = []): array
    {
        return array_merge([
            'request_id' => $snapshot->request_id,
            'algorithm_version' => 'project-matching-v1',
            'configuration_version' => 'project-matching-config-v1',
            'recommendation' => [
                'project_id' => $snapshot->project_id,
                'project_version' => $snapshot->project_version,
                'eligibility_state' => 'eligible',
                'matching_state' => 'scored',
                'score' => 72.5,
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
                'limiting_factors' => ['Availability overlap is narrow.'],
                'skill_results' => [
                    [
                        'skill_id' => 1,
                        'skill_name' => 'PHP',
                        'current_level' => 3.5,
                        'minimum_level' => 3.0,
                        'gap' => 0.0,
                        'match_ratio' => 1.0,
                        'is_critical_entry' => false,
                        'status' => 'met',
                    ],
                ],
            ],
        ], $overrides);
    }

    private function assertFailsWith(string $codeName, int $status, callable $callback): IntelligenceException
    {
        try {
            $callback();
        } catch (IntelligenceException $e) {
            $this->assertSame($codeName, $e->codeName);
            $this->assertSame($status, $e->status);

            return $e;
        }

        $this->fail("Expected IntelligenceException [{$codeName}] but none was thrown.");
    }

    // --------------------------------------------------------- gating tests

    public function test_disabled_integration_is_rejected_before_any_request(): void
    {
        config(['services.data_science.project_matching_enabled' => false]);

        Http::fake();
        $snapshot = $this->makeSnapshot();

        $this->assertFailsWith('INTELLIGENCE_NOT_CONFIGURED', 503, fn () => $this->service->match($snapshot));

        Http::assertNothingSent();
    }

    public function test_missing_service_token_is_rejected_before_any_request(): void
    {
        config(['services.data_science.service_token' => null]);

        Http::fake();
        $snapshot = $this->makeSnapshot();

        $this->assertFailsWith('INTELLIGENCE_NOT_CONFIGURED', 503, fn () => $this->service->match($snapshot));

        Http::assertNothingSent();
    }

    public function test_pending_snapshot_is_rejected(): void
    {
        Http::fake();
        $snapshot = $this->makeSnapshot();
        $snapshot->status = ProjectMatchingSnapshot::STATUS_PENDING;

        $this->assertFailsWith('PROJECT_MATCH_INVALID_SNAPSHOT', 422, fn () => $this->service->match($snapshot));

        Http::assertNothingSent();
    }

    public function test_ineligible_snapshot_is_rejected(): void
    {
        Http::fake();
        $snapshot = $this->makeSnapshot();

        $data = $snapshot->snapshot;
        $data['validation']['eligible'] = false;
        $snapshot->snapshot = $data;

        $this->assertFailsWith('PROJECT_MATCH_INELIGIBLE', 422, fn () => $this->service->match($snapshot));

        Http::assertNothingSent();
    }

    // -------------------------------------------------------- happy path

    public function test_successful_match_returns_normalized_result(): void
    {
        [$project] = $this->createProjectWithSkill();
        $learner = $this->createLearner();

        SkillEvaluation::create([
            'student_profile_id' => $learner->studentProfile->id,
            'skill_id' => $project->requiredSkills->first()->skill_id,
            'level' => 3.5,
            'confidence' => 1.0,
            'algorithm_version' => 'test-v1',
            'calculated_at' => now(),
        ]);

        $snapshot = $this->makeSnapshot($learner, $project);

        Http::fake(['*' => Http::response($this->validResponse($snapshot), 200)]);

        $result = $this->service->match($snapshot);

        $this->assertSame($snapshot->request_id, $result['request_id']);
        $this->assertSame('project-matching-v1', $result['algorithm_version']);
        $this->assertSame('project-matching-config-v1', $result['configuration_version']);
        $this->assertSame($project->id, $result['project_id']);
        $this->assertSame($snapshot->project_version, $result['project_version']);
        $this->assertSame('eligible', $result['eligibility_state']);
        $this->assertSame('scored', $result['matching_state']);
        $this->assertSame(72.5, $result['score']);
        $this->assertCount(5, $result['factor_scores']);
        $this->assertCount(5, $result['weighted_contributions']);
        $this->assertCount(1, $result['skill_results']);
        $this->assertSame('met', $result['skill_results'][0]['status']);
    }

    public function test_request_is_sent_to_the_agreed_endpoint_with_auth_and_correlation(): void
    {
        [$project, $skill] = $this->createProjectWithSkill();
        $learner = $this->createLearner();

        SkillEvaluation::create([
            'student_profile_id' => $learner->studentProfile->id,
            'skill_id' => $skill->id,
            'level' => 4.0,
            'confidence' => 1.0,
            'algorithm_version' => 'test-v1',
            'calculated_at' => now(),
        ]);

        $snapshot = $this->makeSnapshot($learner, $project);

        Http::fake(['*' => Http::response($this->validResponse($snapshot), 200)]);

        $this->service->match($snapshot);

        Http::assertSent(function ($request) use ($snapshot, $project, $skill, $learner) {
            $this->assertSame(
                'https://intelligence.test/api/v1/project-matching',
                $request->url(),
            );

            $this->assertSame([$snapshot->request_id], $request->header('X-Request-ID'));
            $this->assertSame(['Bearer test-service-token'], $request->header('Authorization'));

            $body = $request->data();

            $this->assertSame($snapshot->request_id, $body['request_id']);
            $this->assertSame('project-matching-v1', $body['algorithm_version']);
            $this->assertSame('project-matching-config-v1', $body['configuration_version']);
            $this->assertSame($snapshot->project_version, $body['project_version']);
            $this->assertSame($project->id, $body['project']['id']);
            $this->assertSame($project->version, $body['project']['version']);
            $this->assertSame($learner->studentProfile->id, $body['learner']['student_profile_id']);
            $this->assertSame($learner->id, $body['learner']['user_id']);
            $this->assertSame($skill->id, $body['required_skills'][0]['skill_id']);
            $this->assertSame('eligible', $body['validation']['eligibility_state']);
            $this->assertSame('validated', $body['validation']['validation_state']);

            return true;
        });
    }

    // ------------------------------------------------- correlation failures

    public function test_request_id_mismatch_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        Http::fake(['*' => Http::response(
            $this->validResponse($snapshot, ['request_id' => 'a-different-request-id']),
            200,
        )]);

        $this->assertFailsWith('INTELLIGENCE_RESPONSE_MISMATCH', 502, fn () => $this->service->match($snapshot));
    }

    public function test_project_id_mismatch_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        $body = $this->validResponse($snapshot);
        $body['recommendation']['project_id'] = $snapshot->project_id + 999;

        Http::fake(['*' => Http::response($body, 200)]);

        $this->assertFailsWith('INTELLIGENCE_RESPONSE_MISMATCH', 502, fn () => $this->service->match($snapshot));
    }

    public function test_project_version_mismatch_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        $body = $this->validResponse($snapshot);
        $body['recommendation']['project_version'] = $snapshot->project_version + 5;

        Http::fake(['*' => Http::response($body, 200)]);

        $this->assertFailsWith('INTELLIGENCE_RESPONSE_MISMATCH', 502, fn () => $this->service->match($snapshot));
    }

    public function test_algorithm_version_mismatch_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        Http::fake(['*' => Http::response(
            $this->validResponse($snapshot, ['algorithm_version' => 'project-matching-v9']),
            200,
        )]);

        $this->assertFailsWith('INTELLIGENCE_RESPONSE_MISMATCH', 502, fn () => $this->service->match($snapshot));
    }

    public function test_configuration_version_mismatch_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        Http::fake(['*' => Http::response(
            $this->validResponse($snapshot, ['configuration_version' => 'project-matching-config-v9']),
            200,
        )]);

        $this->assertFailsWith('INTELLIGENCE_RESPONSE_MISMATCH', 502, fn () => $this->service->match($snapshot));
    }

    public function test_missing_algorithm_version_is_rejected(): void
    {
        // algorithm_version is REQUIRED by the agreed contract: the service
        // must echo the version Laravel sent, never omit it and never
        // substitute its own default.
        $snapshot = $this->makeSnapshot();

        $body = $this->validResponse($snapshot);
        unset($body['algorithm_version']);

        Http::fake(['*' => Http::response($body, 200)]);

        $this->assertFailsWith('INTELLIGENCE_INVALID_RESPONSE', 502, fn () => $this->service->match($snapshot));
    }

    public function test_missing_configuration_version_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        $body = $this->validResponse($snapshot);
        unset($body['configuration_version']);

        Http::fake(['*' => Http::response($body, 200)]);

        $this->assertFailsWith('INTELLIGENCE_INVALID_RESPONSE', 502, fn () => $this->service->match($snapshot));
    }

    public function test_null_algorithm_version_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        Http::fake(['*' => Http::response($this->validResponse($snapshot, ['algorithm_version' => null]), 200)]);

        $this->assertFailsWith('INTELLIGENCE_INVALID_RESPONSE', 502, fn () => $this->service->match($snapshot));
    }

    public function test_empty_algorithm_version_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        Http::fake(['*' => Http::response($this->validResponse($snapshot, ['algorithm_version' => '  ']), 200)]);

        $this->assertFailsWith('INTELLIGENCE_INVALID_RESPONSE', 502, fn () => $this->service->match($snapshot));
    }

    public function test_null_configuration_version_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        Http::fake(['*' => Http::response($this->validResponse($snapshot, ['configuration_version' => null]), 200)]);

        $this->assertFailsWith('INTELLIGENCE_INVALID_RESPONSE', 502, fn () => $this->service->match($snapshot));
    }

    public function test_empty_configuration_version_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        Http::fake(['*' => Http::response($this->validResponse($snapshot, ['configuration_version' => '']), 200)]);

        $this->assertFailsWith('INTELLIGENCE_INVALID_RESPONSE', 502, fn () => $this->service->match($snapshot));
    }

    // --------------------------------------------------- schema violations

    public function test_missing_recommendation_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        $body = $this->validResponse($snapshot);
        unset($body['recommendation']);

        Http::fake(['*' => Http::response($body, 200)]);

        $this->assertFailsWith('INTELLIGENCE_INVALID_RESPONSE', 502, fn () => $this->service->match($snapshot));
    }

    public function test_incomplete_recommendation_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        $body = $this->validResponse($snapshot);
        unset($body['recommendation']['score']);

        Http::fake(['*' => Http::response($body, 200)]);

        $this->assertFailsWith('INTELLIGENCE_INVALID_RESPONSE', 502, fn () => $this->service->match($snapshot));
    }

    public function test_out_of_range_score_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        $body = $this->validResponse($snapshot);
        $body['recommendation']['score'] = 140.0;

        Http::fake(['*' => Http::response($body, 200)]);

        $this->assertFailsWith('INTELLIGENCE_INVALID_RESPONSE', 502, fn () => $this->service->match($snapshot));
    }

    public function test_invalid_matching_state_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        $body = $this->validResponse($snapshot);
        $body['recommendation']['matching_state'] = 'unknown';

        Http::fake(['*' => Http::response($body, 200)]);

        $this->assertFailsWith('INTELLIGENCE_INVALID_RESPONSE', 502, fn () => $this->service->match($snapshot));
    }

    public function test_invalid_skill_result_status_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        $body = $this->validResponse($snapshot);
        $body['recommendation']['skill_results'][0]['status'] = 'not_a_real_status';

        Http::fake(['*' => Http::response($body, 200)]);

        $this->assertFailsWith('INTELLIGENCE_INVALID_RESPONSE', 502, fn () => $this->service->match($snapshot));
    }

    public function test_incomplete_skill_result_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        $body = $this->validResponse($snapshot);
        unset($body['recommendation']['skill_results'][0]['match_ratio']);

        Http::fake(['*' => Http::response($body, 200)]);

        $this->assertFailsWith('INTELLIGENCE_INVALID_RESPONSE', 502, fn () => $this->service->match($snapshot));
    }

    public function test_missing_skill_results_is_accepted(): void
    {
        // skill_results is OPTIONAL in ProjectRecommendationResult, so its
        // absence must not fail the call.
        $snapshot = $this->makeSnapshot();

        $body = $this->validResponse($snapshot);
        unset($body['recommendation']['skill_results']);

        Http::fake(['*' => Http::response($body, 200)]);

        $result = $this->service->match($snapshot);

        $this->assertSame([], $result['skill_results']);
        $this->assertSame(72.5, $result['score']);
    }

    public function test_empty_skill_results_array_is_accepted(): void
    {
        $snapshot = $this->makeSnapshot();

        $body = $this->validResponse($snapshot);
        $body['recommendation']['skill_results'] = [];

        Http::fake(['*' => Http::response($body, 200)]);

        $this->assertSame([], $this->service->match($snapshot)['skill_results']);
    }

    public function test_null_skill_results_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        $body = $this->validResponse($snapshot);
        $body['recommendation']['skill_results'] = null;

        Http::fake(['*' => Http::response($body, 200)]);

        $this->assertFailsWith('INTELLIGENCE_INVALID_RESPONSE', 502, fn () => $this->service->match($snapshot));
    }

    public function test_string_skill_results_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        $body = $this->validResponse($snapshot);
        $body['recommendation']['skill_results'] = 'not-an-array';

        Http::fake(['*' => Http::response($body, 200)]);

        $this->assertFailsWith('INTELLIGENCE_INVALID_RESPONSE', 502, fn () => $this->service->match($snapshot));
    }

    public function test_object_skill_results_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        $body = $this->validResponse($snapshot);

        // A JSON OBJECT on the wire, not an array: it encodes as
        // {"skill_id":1} and decodes to a PHP array whose values are not
        // objects — so it must be rejected, never silently skipped.
        $body['recommendation']['skill_results'] = ['skill_id' => 1];
        $this->assertStringStartsWith('{', json_encode($body['recommendation']['skill_results']));

        Http::fake(['*' => Http::response($body, 200)]);

        $this->assertFailsWith('INTELLIGENCE_INVALID_RESPONSE', 502, fn () => $this->service->match($snapshot));
    }

    public function test_non_object_skill_result_entry_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        $body = $this->validResponse($snapshot);
        $body['recommendation']['skill_results'] = ['not-an-object'];

        Http::fake(['*' => Http::response($body, 200)]);

        $this->assertFailsWith('INTELLIGENCE_INVALID_RESPONSE', 502, fn () => $this->service->match($snapshot));
    }

    public function test_non_json_response_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        Http::fake(['*' => Http::response('not-json{', 200)]);

        $this->assertFailsWith('INTELLIGENCE_INVALID_RESPONSE', 502, fn () => $this->service->match($snapshot));
    }

    // ------------------------------------------------------ transport errors

    public function test_upstream_422_is_reported_as_validation_error(): void
    {
        $snapshot = $this->makeSnapshot();

        Http::fake(['*' => Http::response(['detail' => 'invalid payload'], 422)]);

        $this->assertFailsWith('INTELLIGENCE_VALIDATION_ERROR', 422, fn () => $this->service->match($snapshot));
    }

    public function test_upstream_500_is_reported_as_unavailable(): void
    {
        $snapshot = $this->makeSnapshot();

        Http::fake(['*' => Http::response(['detail' => 'boom'], 500)]);

        $this->assertFailsWith('INTELLIGENCE_UNAVAILABLE', 503, fn () => $this->service->match($snapshot));
    }

    public function test_unexpected_status_is_reported_as_invalid_response(): void
    {
        $snapshot = $this->makeSnapshot();

        Http::fake(['*' => Http::response([], 403)]);

        $this->assertFailsWith('INTELLIGENCE_INVALID_RESPONSE', 502, fn () => $this->service->match($snapshot));
    }

    public function test_connection_failure_is_reported_as_unavailable(): void
    {
        $snapshot = $this->makeSnapshot();

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->assertFailsWith('INTELLIGENCE_UNAVAILABLE', 503, fn () => $this->service->match($snapshot));
    }
}
