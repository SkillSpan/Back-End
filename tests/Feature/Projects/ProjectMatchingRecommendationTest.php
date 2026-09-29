<?php

namespace Tests\Feature\Projects;

use App\Exceptions\IntelligenceException;
use App\Models\AlgorithmConfiguration;
use App\Models\Project;
use App\Models\ProjectMatchingSnapshot;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Projects\ProjectMatchingRecommendationService;
use App\Services\Projects\ProjectMatchingSnapshotService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 11 — project matching recommendation persistence and retrieval.
 */
class ProjectMatchingRecommendationTest extends TestCase
{
    use RefreshDatabase;

    private ProjectMatchingRecommendationService $service;

    private ProjectMatchingSnapshotService $snapshotService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ProjectMatchingRecommendationService;
        $this->snapshotService = new ProjectMatchingSnapshotService;

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

    private function createLearner(string $email = 'learner@test.com'): User
    {
        $user = User::forceCreate([
            'name' => 'Test Learner',
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

    private function makeSnapshot(?User $learner = null, ?Project $project = null): ProjectMatchingSnapshot
    {
        $learner ??= $this->createLearner();
        $project ??= $this->createProject();

        $project->load(['requiredSkills.skill', 'eligibilityConstraints']);

        return $this->snapshotService->createForProject($project, $learner);
    }

    /**
     * The normalized shape ProjectMatchingService::match() returns.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validResult(ProjectMatchingSnapshot $snapshot, array $overrides = []): array
    {
        return array_merge([
            'request_id' => $snapshot->request_id,
            'algorithm_version' => 'project-matching-v1',
            'configuration_version' => 'project-matching-config-v1',
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
            'skill_results' => [],
        ], $overrides);
    }

    private function assertFailsWith(string $codeName, callable $callback): void
    {
        try {
            $callback();
        } catch (IntelligenceException $e) {
            $this->assertSame($codeName, $e->codeName);

            return;
        }

        $this->fail("Expected IntelligenceException [{$codeName}] but none was thrown.");
    }

    /**
     * Persist as the snapshot's own learner unless a caller explicitly wants
     * to act as someone else (the cross-learner ownership test does).
     */
    private function persist(
        ProjectMatchingSnapshot $snapshot,
        array $result,
        ?User $learner = null,
    ): Recommendation {
        return $this->service->persist(
            $learner ?? $snapshot->studentProfile->user,
            $snapshot,
            $result,
        );
    }

    // -------------------------------------------------------- persistence

    public function test_validated_result_is_persisted(): void
    {
        $learner = $this->createLearner();
        $project = $this->createProject();
        $snapshot = $this->makeSnapshot($learner, $project);

        $recommendation = $this->persist($snapshot, $this->validResult($snapshot));

        $this->assertDatabaseHas('recommendations', [
            'id' => $recommendation->id,
            'user_id' => $learner->id,
            'project_matching_snapshot_id' => $snapshot->id,
            'type' => 'project',
            'candidate_type' => 'project',
            'candidate_id' => $project->id,
            'eligibility_state' => 'eligible',
            'matching_state' => 'scored',
        ]);

        $this->assertSame(72.5, (float) $recommendation->score);
    }

    public function test_multiple_recommendations_are_persisted_independently(): void
    {
        $learner = $this->createLearner();

        $first = $this->makeSnapshot($learner, $this->createProject(['title' => 'First']));
        $second = $this->makeSnapshot($learner, $this->createProject(['title' => 'Second']));

        $this->persist($first, $this->validResult($first));
        $this->persist($second, $this->validResult($second, ['score' => 55.0]));

        $this->assertDatabaseCount('recommendations', 2);

        $this->assertDatabaseHas('recommendations', [
            'project_matching_snapshot_id' => $first->id,
            'candidate_id' => $first->project_id,
        ]);

        $this->assertDatabaseHas('recommendations', [
            'project_matching_snapshot_id' => $second->id,
            'candidate_id' => $second->project_id,
        ]);
    }

    public function test_recommendation_belongs_to_the_snapshot_learner(): void
    {
        $first = $this->createLearner('first@test.com');
        $second = $this->createLearner('second@test.com');

        $project = $this->createProject();

        $firstSnapshot = $this->makeSnapshot($first, $project);
        $secondSnapshot = $this->makeSnapshot($second, $project);

        $a = $this->persist($firstSnapshot, $this->validResult($firstSnapshot));
        $b = $this->persist($secondSnapshot, $this->validResult($secondSnapshot));

        $this->assertSame($first->id, $a->user_id);
        $this->assertSame($second->id, $b->user_id);
        $this->assertNotSame($a->id, $b->id);
    }

    public function test_version_metadata_is_persisted(): void
    {
        $snapshot = $this->makeSnapshot();

        $recommendation = $this->persist($snapshot, $this->validResult($snapshot));

        $this->assertSame('project-matching-v1', $recommendation->algorithm_version);
        $this->assertSame('project-matching-config-v1', $recommendation->configuration_version);
        $this->assertSame($snapshot->project_version, $recommendation->project_version);
        $this->assertNotNull($recommendation->generated_at);
    }

    public function test_explanation_and_limiting_factors_are_persisted(): void
    {
        $snapshot = $this->makeSnapshot();

        $recommendation = $this->persist($snapshot, $this->validResult($snapshot, [
            'explanation' => ['Reason one.', 'Reason two.'],
            'limiting_factors' => ['Factor A.', 'Factor B.'],
        ]));

        $this->assertSame("Reason one.\nReason two.", $recommendation->reasons);
        $this->assertSame(['Factor A.', 'Factor B.'], $recommendation->limiting_factors);
    }

    public function test_factor_scores_and_weighted_contributions_are_persisted(): void
    {
        $snapshot = $this->makeSnapshot();

        $result = $this->validResult($snapshot);
        $recommendation = $this->persist($snapshot, $result);

        // Loose comparison: whole floats round-trip through JSON as ints.
        $this->assertEquals($result['factor_scores'], $recommendation->factors);
        $this->assertEquals($result['weighted_contributions'], $recommendation->weighted_contributions);
    }

    // ------------------------------------------------------ integrity

    public function test_duplicate_persist_for_the_same_snapshot_is_idempotent(): void
    {
        $snapshot = $this->makeSnapshot();
        $result = $this->validResult($snapshot);

        $first = $this->persist($snapshot, $result);
        $second = $this->persist($snapshot, $result);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('recommendations', 1);
    }

    public function test_pending_snapshot_is_rejected_without_persisting(): void
    {
        $snapshot = $this->makeSnapshot();
        $snapshot->status = ProjectMatchingSnapshot::STATUS_PENDING;

        $this->assertFailsWith(
            'PROJECT_MATCH_INVALID_SNAPSHOT',
            fn () => $this->persist($snapshot, $this->validResult($snapshot)),
        );

        $this->assertDatabaseCount('recommendations', 0);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function malformedResults(): array
    {
        return [
            'missing score' => [['score' => null]],
            'missing matching_state' => [['matching_state' => null]],
            'missing project_id' => [['project_id' => null]],
            'missing project_version' => [['project_version' => null]],
            'missing eligibility_state' => [['eligibility_state' => null]],
            'missing factor_scores' => [['factor_scores' => null]],
            'non numeric score' => [['score' => 'high']],
            'non array factor_scores' => [['factor_scores' => 'none']],
            'invalid eligibility_state' => [['eligibility_state' => 'maybe']],
            'invalid matching_state' => [['matching_state' => 'unknown']],
            'zero project_id' => [['project_id' => 0]],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @dataProvider malformedResults
     */
    public function test_malformed_result_is_rejected_without_persisting(array $overrides): void
    {
        $snapshot = $this->makeSnapshot();

        $this->assertFailsWith(
            'PROJECT_MATCH_INVALID_RESULT',
            fn () => $this->persist($snapshot, $this->validResult($snapshot, $overrides)),
        );

        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_persistence_failure_rolls_back_and_writes_nothing(): void
    {
        $snapshot = $this->makeSnapshot();

        Recommendation::creating(function () {
            throw new RuntimeException('simulated persistence failure');
        });

        try {
            $this->persist($snapshot, $this->validResult($snapshot));
            $this->fail('Expected the simulated persistence failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated persistence failure', $e->getMessage());
        }

        $this->assertDatabaseCount('recommendations', 0);
    }

    // -------------------------------------------------- deduplication

    public function test_repeated_matching_across_distinct_snapshots_does_not_duplicate(): void
    {
        // The real-world case: every POST /projects/{id}/match creates a NEW
        // ProjectMatchingSnapshot (Task 8 is deliberately append-only), so
        // keying dedup on the snapshot id would write a duplicate row on every
        // repeated request. The logical result is identical, so one row.
        $learner = $this->createLearner();
        $project = $this->createProject();

        $ids = [];

        foreach (range(1, 3) as $ignored) {
            $snapshot = $this->makeSnapshot($learner, $project);
            $ids[] = $this->persist($snapshot, $this->validResult($snapshot))->id;
        }

        $this->assertCount(1, array_unique($ids), 'Repeated matching must reuse one recommendation.');
        $this->assertDatabaseCount('recommendations', 1);
        $this->assertDatabaseCount('project_matching_snapshots', 3);
    }

    public function test_existing_recommendation_is_reused_for_an_identical_result(): void
    {
        // Persistence has NO application-level existence pre-check — the unique
        // index on dedup_key is the guard, so there is no TOCTOU window. The
        // second call below therefore genuinely drives the conflict-recovery
        // branch: its INSERT violates the constraint and the service returns
        // the row that already represents that logical result instead of
        // raising.
        //
        // LIMITATION: this exercises the recovery branch deterministically but
        // does NOT prove real concurrent safety. The test environment runs
        // SQLite :memory:, which cannot execute two writers in parallel, so a
        // genuine two-writer race is not reproducible here.
        $learner = $this->createLearner();
        $project = $this->createProject();

        $firstSnapshot = $this->makeSnapshot($learner, $project);
        $first = $this->persist($firstSnapshot, $this->validResult($firstSnapshot));

        $secondSnapshot = $this->makeSnapshot($learner, $project);
        $second = $this->persist($secondSnapshot, $this->validResult($secondSnapshot));

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('recommendations', 1);
    }

    public function test_a_changed_result_is_kept_as_its_own_recommendation(): void
    {
        // A different score is a genuinely different matching result, so it
        // must not be collapsed into the existing row.
        $learner = $this->createLearner();
        $project = $this->createProject();

        $firstSnapshot = $this->makeSnapshot($learner, $project);
        $this->persist($firstSnapshot, $this->validResult($firstSnapshot, ['score' => 40.0]));

        $secondSnapshot = $this->makeSnapshot($learner, $project);
        $this->persist($secondSnapshot, $this->validResult($secondSnapshot, ['score' => 88.0]));

        $this->assertDatabaseCount('recommendations', 2);
    }

    public function test_a_new_project_version_is_kept_as_its_own_recommendation(): void
    {
        $learner = $this->createLearner();
        $project = $this->createProject();

        $firstSnapshot = $this->makeSnapshot($learner, $project);
        $this->persist($firstSnapshot, $this->validResult($firstSnapshot));

        $project->update(['version' => 2]);

        $secondSnapshot = $this->makeSnapshot($learner, $project);
        $this->assertSame(2, $secondSnapshot->project_version);

        $this->persist($secondSnapshot, $this->validResult($secondSnapshot));

        $this->assertDatabaseCount('recommendations', 2);
        $this->assertDatabaseHas('recommendations', ['project_version' => 1]);
        $this->assertDatabaseHas('recommendations', ['project_version' => 2]);
    }

    public function test_dedup_key_is_unique_at_the_database_level(): void
    {
        // The guarantee must come from the database, not only from the
        // service's existence check.
        $learner = $this->createLearner();
        $snapshot = $this->makeSnapshot($learner);
        $recommendation = $this->persist($snapshot, $this->validResult($snapshot));

        $this->expectException(QueryException::class);

        DB::table('recommendations')->insert([
            'user_id' => $recommendation->user_id,
            'type' => 'project',
            'candidate_type' => 'project',
            'candidate_id' => $recommendation->candidate_id,
            'dedup_key' => $recommendation->dedup_key,
            'eligibility_state' => 'eligible',
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_unrelated_database_errors_are_not_swallowed(): void
    {
        $snapshot = $this->makeSnapshot();

        // A non-integrity failure must surface, not be mistaken for a dedup.
        Recommendation::creating(function () {
            throw new QueryException('sqlite', 'insert into "recommendations"', [], new PDOException('syntax error'));
        });

        $this->expectException(QueryException::class);

        $this->persist($snapshot, $this->validResult($snapshot));
    }

    // ---------------------------------------------------- consistency

    public function test_snapshot_belonging_to_another_learner_is_rejected(): void
    {
        $owner = $this->createLearner('owner@test.com');
        $intruder = $this->createLearner('intruder@test.com');

        $snapshot = $this->makeSnapshot($owner);

        $this->assertFailsWith(
            'PROJECT_MATCH_SNAPSHOT_NOT_OWNED',
            fn () => $this->persist($snapshot, $this->validResult($snapshot), $intruder),
        );

        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_mismatched_project_id_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        $this->assertFailsWith(
            'PROJECT_MATCH_INCONSISTENT_RESULT',
            fn () => $this->persist($snapshot, $this->validResult($snapshot, [
                'project_id' => $snapshot->project_id + 999,
            ])),
        );

        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_mismatched_project_version_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        $this->assertFailsWith(
            'PROJECT_MATCH_INCONSISTENT_RESULT',
            fn () => $this->persist($snapshot, $this->validResult($snapshot, [
                'project_version' => $snapshot->project_version + 5,
            ])),
        );

        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_mismatched_algorithm_version_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        $this->assertFailsWith(
            'PROJECT_MATCH_INCONSISTENT_RESULT',
            fn () => $this->persist($snapshot, $this->validResult($snapshot, [
                'algorithm_version' => 'project-matching-v9',
            ])),
        );

        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_missing_algorithm_version_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        $this->assertFailsWith(
            'PROJECT_MATCH_INCONSISTENT_RESULT',
            fn () => $this->persist($snapshot, $this->validResult($snapshot, [
                'algorithm_version' => null,
            ])),
        );

        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_mismatched_configuration_version_is_rejected(): void
    {
        $snapshot = $this->makeSnapshot();

        $this->assertFailsWith(
            'PROJECT_MATCH_INCONSISTENT_RESULT',
            fn () => $this->persist($snapshot, $this->validResult($snapshot, [
                'configuration_version' => 'project-matching-config-v9',
            ])),
        );

        $this->assertDatabaseCount('recommendations', 0);
    }

    // ------------------------------------------------------- retrieval

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/recommendations')->assertStatus(401);
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

        $this->getJson('/api/v1/recommendations')
            ->assertStatus(403)
            ->assertJsonPath('code', 'LEARNER_ONLY');
    }

    public function test_learner_receives_only_their_own_recommendations(): void
    {
        $mine = $this->createLearner('mine@test.com');
        $theirs = $this->createLearner('theirs@test.com');

        $mySnapshot = $this->makeSnapshot($mine, $this->createProject(['title' => 'Mine']));
        $theirSnapshot = $this->makeSnapshot($theirs, $this->createProject(['title' => 'Theirs']));

        $this->persist($mySnapshot, $this->validResult($mySnapshot));
        $this->persist($theirSnapshot, $this->validResult($theirSnapshot));

        Sanctum::actingAs($mine);

        $response = $this->getJson('/api/v1/recommendations');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.project_id', $mySnapshot->project_id)
            ->assertJsonPath('data.0.project.title', 'Mine');

        $this->assertCount(1, $response->json('data'));
    }

    public function test_non_project_recommendations_are_not_returned(): void
    {
        $learner = $this->createLearner();

        Recommendation::create([
            'user_id' => $learner->id,
            'type' => 'roadmap_action',
            'candidate_type' => 'roadmap_action',
            'candidate_id' => 1,
            'generated_at' => now(),
        ]);

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/recommendations')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data', []);
    }

    public function test_retrieval_is_paginated_and_newest_first(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $projectIds = [];

        foreach (['First', 'Second', 'Third'] as $title) {
            $snapshot = $this->makeSnapshot($learner, $this->createProject(['title' => $title]));
            $this->persist($snapshot, $this->validResult($snapshot));
            $projectIds[] = $snapshot->project_id;
        }

        $response = $this->getJson('/api/v1/recommendations?per_page=2');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2);

        $this->assertCount(2, $response->json('data'));

        // Newest first: the third project was persisted last.
        $this->assertSame($projectIds[2], $response->json('data.0.project_id'));
    }

    public function test_per_page_is_clamped(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/recommendations?per_page=5000')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 100);

        $this->getJson('/api/v1/recommendations?per_page=0')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 15);
    }

    public function test_retrieval_exposes_score_and_version_metadata(): void
    {
        $learner = $this->createLearner();
        $snapshot = $this->makeSnapshot($learner);
        $this->persist($snapshot, $this->validResult($snapshot));

        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/recommendations')
            ->assertStatus(200)
            ->assertJsonPath('data.0.algorithm_version', 'project-matching-v1')
            ->assertJsonPath('data.0.configuration_version', 'project-matching-config-v1')
            ->assertJsonPath('data.0.eligibility_state', 'eligible')
            ->assertJsonPath('data.0.matching_state', 'scored')
            ->assertJsonPath('data.0.reasons', 'Strong alignment with the project skill set.');
    }

    // ------------------------------------------- end-to-end via the match endpoint

    public function test_match_endpoint_persists_the_recommendation(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        config([
            'services.data_science.url' => 'https://intelligence.test',
            'services.data_science.service_token' => 'test-service-token',
            'services.data_science.project_matching_enabled' => true,
            'services.data_science.project_matching_path' => '/api/v1/project-matching',
        ]);

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
                    'explanation' => ['Stored end to end.'],
                    'limiting_factors' => [],
                    'skill_results' => [],
                ],
            ], 200);
        });

        $project = $this->createProject();

        $this->postJson('/api/v1/projects/'.$project->id.'/match')
            ->assertStatus(200)
            ->assertJsonPath('data.project_id', $project->id);

        $this->assertDatabaseCount('recommendations', 1);

        $this->assertDatabaseHas('recommendations', [
            'user_id' => $learner->id,
            'candidate_id' => $project->id,
            'type' => 'project',
            'algorithm_version' => 'project-matching-v1',
            'configuration_version' => 'project-matching-config-v1',
        ]);

        // The stored row is retrievable through the Task 11 endpoint.
        $this->getJson('/api/v1/recommendations')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.project_id', $project->id);
    }

    public function test_match_endpoint_does_not_persist_when_the_service_fails(): void
    {
        $learner = $this->createLearner();
        Sanctum::actingAs($learner);

        config([
            'services.data_science.url' => 'https://intelligence.test',
            'services.data_science.service_token' => 'test-service-token',
            'services.data_science.project_matching_enabled' => true,
            'services.data_science.project_matching_path' => '/api/v1/project-matching',
        ]);

        Http::fake(['*' => Http::response(['detail' => 'invalid'], 422)]);

        $project = $this->createProject();

        $this->postJson('/api/v1/projects/'.$project->id.'/match')
            ->assertStatus(422)
            ->assertJsonPath('code', 'INTELLIGENCE_VALIDATION_ERROR');

        $this->assertDatabaseCount('recommendations', 0);
    }
}
