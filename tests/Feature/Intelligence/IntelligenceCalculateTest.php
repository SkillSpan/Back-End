<?php

namespace Tests\Feature\Intelligence;

use App\Models\AlgorithmConfiguration;
use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\DecisionSnapshot;
use App\Models\ReadinessResult;
use App\Models\Roadmap;
use App\Models\RoadmapAction;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\SkillGapResult;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntelligenceCalculateTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    private Role $companyRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        $this->companyRole = Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);

        config(['services.data_science.service_token' => 'test-service-token']);

        AlgorithmConfiguration::create([
            'name' => 'intelligence',
            'version' => 1,
            'status' => 'active',
            'config' => ['skill_scale' => [0, 5]],
            'activated_at' => now(),
        ]);
    }

    public function test_unauthenticated_user_is_rejected(): void
    {
        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => 1])
            ->assertStatus(401);
    }

    public function test_non_learner_cannot_calculate(): void
    {
        $user = $this->createUserWithRole($this->companyRole);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => 1])
            ->assertStatus(403)
            ->assertJsonPath('code', 'LEARNER_ONLY');
    }

    public function test_learner_without_profile_is_rejected(): void
    {
        $user = $this->createUserWithRole($this->learnerRole);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => 1])
            ->assertStatus(422)
            ->assertJsonPath('code', 'STUDENT_PROFILE_NOT_FOUND');
    }

    public function test_unknown_role_returns_404(): void
    {
        [$user] = $this->createScenario();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => 99999])
            ->assertStatus(404)
            ->assertJsonPath('code', 'CAREER_ROLE_NOT_FOUND');
    }

    public function test_draft_role_is_rejected(): void
    {
        [$user, , $role] = $this->createScenario('draft');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CAREER_ROLE_NOT_APPROVED');
    }

    public function test_retired_role_is_rejected(): void
    {
        [$user, , $role] = $this->createScenario('retired');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CAREER_ROLE_NOT_APPROVED');
    }

    public function test_role_without_skills_is_rejected(): void
    {
        $user = $this->createUserWithRole($this->learnerRole);
        $profile = StudentProfile::forceCreate(['user_id' => $user->id]);
        $role = CareerRole::forceCreate([
            'title' => 'Empty Role',
            'slug' => 'empty-role-'.uniqid(),
            'version' => 1,
            'status' => 'approved',
        ]);
        $profile->update(['primary_career_role_id' => $role->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CAREER_ROLE_NO_SKILLS');
    }

    public function test_learner_missing_one_required_skill_evaluation_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        SkillEvaluation::query()
            ->where('student_profile_id', $profile->id)
            ->where('skill_id', $roleSkills[2]->skill_id)
            ->delete();

        Http::fake();

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ASSESSMENT_INCOMPLETE')
            ->assertJsonPath('details.missing_skill_ids.0', (int) $roleSkills[2]->skill_id);

        Http::assertNothingSent();

        $this->assertSame(0, DecisionSnapshot::count());
        $this->assertNothingPersisted($profile);
    }

    public function test_successful_calculation_persists_snapshot_gaps_readiness(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);
        $this->fakeAllSuccess($profile, $role, $roleSkills);

        $response = $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id]);

        $response->assertStatus(201);

        $snapshot = DecisionSnapshot::query()
            ->where('student_profile_id', $profile->id)
            ->where('status', 'succeeded')
            ->first();

        $this->assertNotNull($snapshot, 'a succeeded decision snapshot must exist');
        $this->assertNotNull($snapshot->decision_uuid);
        $this->assertSame((int) $role->version, $snapshot->career_role_version);
        $this->assertSame('config-v1', $snapshot->configuration_version);
        $this->assertNotNull($snapshot->calculated_at);

        $snapshotData = $snapshot->snapshot;
        $this->assertSame($profile->availability, $snapshotData['learner']['availability']);
        $this->assertSame((int) $role->id, $snapshotData['role']['id']);
        $this->assertCount(3, $snapshotData['skills']);

        $this->assertDatabaseHas('readiness_results', [
            'decision_snapshot_id' => $snapshot->id,
            'student_profile_id' => $profile->id,
            'career_role_id' => $role->id,
            'configuration_version' => 'config-v1',
        ]);

        $this->assertSame(3, SkillGapResult::where('decision_snapshot_id', $snapshot->id)->count());

        $response->assertJsonPath('data.decision_id', $snapshot->decision_uuid)
            ->assertJsonPath('data.role_version', $role->version)
            ->assertJsonPath('data.configuration_version', 'config-v1')
            ->assertJsonPath('data.readiness.score', 72.5);
        $this->assertArrayNotHasKey('snapshot', $response->json('data'), 'raw internal snapshot must not be exposed');
    }

    public function test_snapshot_contains_learner_skill_state_and_evidence_summary(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);
        $this->fakeAllSuccess($profile, $role, $roleSkills);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $snapshot = DecisionSnapshot::where('student_profile_id', $profile->id)->first();
        $skills = collect($snapshot->snapshot['skills']);

        $sql = $skills->firstWhere('skill_id', $roleSkills[0]->skill_id);

        $this->assertSame(2.5, (float) $sql['current_level']);
        $this->assertEqualsWithDelta(90.0, (float) $sql['confidence'], 0.0001);
        $this->assertTrue($sql['is_critical']);
        $this->assertArrayHasKey('evidence', $sql);
    }

    public function test_service_token_and_request_id_are_sent_but_not_sanctum_token(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user, ['*']);
        config(['services.data_science.service_token' => 'secret-service-token']);

        $sentAuth = [];
        $sentRequestIds = [];
        $calls = 0;

        Http::fake(function ($request) use (&$sentAuth, &$sentRequestIds, &$calls, $profile, $role, $roleSkills) {
            $sentAuth[] = $request->header('Authorization');
            $sentRequestIds[] = $request->header('X-Request-ID');
            $calls++;

            return Http::response($this->skillGapResponse($profile, $role, $roleSkills), 200);
        });

        $this->postJson(
            '/api/v1/intelligence/calculate',
            ['career_role_id' => $role->id],
            ['X-Request-ID' => 'my-correlation-id'],
        )->assertStatus(201);

        $this->assertSame(1, $calls);

        $flatAuth = collect($sentAuth)->flatten()->map(fn ($value) => (string) $value);
        $flatIds = collect($sentRequestIds)->flatten()->map(fn ($id) => (string) $id);

        $this->assertTrue(
            $flatAuth->every(fn ($value) => str_contains($value, 'Bearer secret-service-token')),
            'the service credential must be sent on every intelligence call',
        );

        $this->assertTrue(
            $flatAuth->every(fn ($value) => ! str_contains($value, '|')),
            'no learner Sanctum token may leak to FastAPI',
        );

        $this->assertTrue(
            $flatIds->every(fn ($id) => $id === 'my-correlation-id'),
            'the incoming X-Request-ID must be propagated unchanged',
        );
    }

    public function test_configuration_version_comes_from_active_configuration(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();

        AlgorithmConfiguration::create([
            'name' => 'intelligence',
            'version' => 2,
            'status' => 'active',
            'config' => [],
            'activated_at' => now(),
        ]);

        Sanctum::actingAs($user);
        $this->fakeAllSuccess($profile, $role, $roleSkills);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->assertJsonPath('data.configuration_version', 'config-v2');
    }

    public function test_missing_active_configuration_is_an_explicit_error(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        AlgorithmConfiguration::query()->update(['status' => 'retired']);
        Sanctum::actingAs($user);
        Http::fake();

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INTELLIGENCE_CONFIGURATION_INVALID');

        $this->assertSame(0, ReadinessResult::count());
        Http::assertNothingSent();
    }

    public function test_unknown_skill_in_gap_response_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $gap = $this->skillGapResponse($profile, $role, $roleSkills);
        $gap['skill_results'][1]['skill_id'] = 999999;

        $this->fakeSequence($gap, $this->readinessResponse($profile, $role), null);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        $this->assertNothingPersisted($profile);
    }

    public function test_duplicate_skill_in_gap_response_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $gap = $this->skillGapResponse($profile, $role, $roleSkills);
        $gap['skill_results'][1]['skill_id'] = $gap['skill_results'][0]['skill_id'];

        $this->fakeSequence($gap, $this->readinessResponse($profile, $role), null);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        $this->assertNothingPersisted($profile);
    }

    public function test_readiness_score_above_100_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $readiness = $this->readinessResponse($profile, $role);
        $readiness['readiness_score'] = 101.5;

        $this->fakeSequence($this->skillGapResponse($profile, $role, $roleSkills), $readiness, null);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        $this->assertNothingPersisted($profile);
    }

    public function test_negative_readiness_score_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $readiness = $this->readinessResponse($profile, $role);
        $readiness['readiness_score'] = -1;

        $this->fakeSequence($this->skillGapResponse($profile, $role, $roleSkills), $readiness, null);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        $this->assertNothingPersisted($profile);
    }

    public function test_role_version_mismatch_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $readiness = $this->readinessResponse($profile, $role);
        $readiness['career_role_version'] = 99;

        $this->fakeSequence($this->skillGapResponse($profile, $role, $roleSkills), $readiness, null);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_RESPONSE_MISMATCH');

        $this->assertNothingPersisted($profile);
    }

    public function test_missing_algorithm_version_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $gap = $this->skillGapResponse($profile, $role, $roleSkills);
        unset($gap['algorithm_version']);

        $this->fakeSequence($gap, null, null);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        $this->assertNothingPersisted($profile);
    }

    public function test_readiness_learner_mismatch_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $readiness = $this->readinessResponse($profile, $role);
        $readiness['student_profile_id'] = $profile->id + 500;

        $this->fakeSequence($this->skillGapResponse($profile, $role, $roleSkills), $readiness, null);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_RESPONSE_MISMATCH');

        $this->assertNothingPersisted($profile);
    }

    public function test_fastapi_timeout_persists_failed_snapshot_only(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(503)
            ->assertJsonPath('code', 'INTELLIGENCE_UNAVAILABLE');

        $snapshot = DecisionSnapshot::where('student_profile_id', $profile->id)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame('failed', $snapshot->status);

        $this->assertNothingPersisted($profile, $snapshot->id);
    }

    public function test_fastapi_422_does_not_persist_results(): void
    {
        [$user, $profile, $role] = $this->createScenario();
        Sanctum::actingAs($user);

        Http::fake(['*' => Http::response(['detail' => 'invalid'], 422)]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INTELLIGENCE_VALIDATION_ERROR');

        $this->assertNothingPersisted($profile);
    }

    public function test_fastapi_500_does_not_persist_results(): void
    {
        [$user, $profile, $role] = $this->createScenario();
        Sanctum::actingAs($user);

        Http::fake(['*' => Http::response(['message' => 'boom'], 500)]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(503)
            ->assertJsonPath('code', 'INTELLIGENCE_UNAVAILABLE');

        $this->assertNothingPersisted($profile);
    }

    public function test_invalid_json_does_not_persist_results(): void
    {
        [$user, $profile, $role] = $this->createScenario();
        Sanctum::actingAs($user);

        Http::fake(['*' => Http::response('not-json{', 200)]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        $this->assertNothingPersisted($profile);
    }

    public function test_unconfigured_service_url_is_reported(): void
    {
        [$user, $profile, $role] = $this->createScenario();
        Sanctum::actingAs($user);
        config(['services.data_science.url' => '']);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(503)
            ->assertJsonPath('code', 'INTELLIGENCE_NOT_CONFIGURED');

        $this->assertNothingPersisted($profile);
    }

    public function test_missing_service_token_fails_locally_without_calling_fastapi(): void
    {
        [$user, $profile, $role] = $this->createScenario();
        Sanctum::actingAs($user);
        config(['services.data_science.service_token' => null]);

        Http::fake();

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(503)
            ->assertJsonPath('code', 'INTELLIGENCE_NOT_CONFIGURED');

        Http::assertNothingSent();

        $snapshot = DecisionSnapshot::where('student_profile_id', $profile->id)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame('failed', $snapshot->status);

        $this->assertNothingPersisted($profile);
    }

    public function test_second_calculation_creates_new_decision_and_preserves_history(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);
        $this->fakeAllSuccess($profile, $role, $roleSkills, 72.5);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->assertJsonPath('data.readiness.score', 72.5);

        $firstSnapshot = DecisionSnapshot::where('student_profile_id', $profile->id)->first();
        $firstReadiness = ReadinessResult::where('decision_snapshot_id', $firstSnapshot->id)->first();
        $firstGaps = SkillGapResult::where('decision_snapshot_id', $firstSnapshot->id)->get();

        $this->fakeAllSuccess($profile, $role, $roleSkills, 88.0);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->assertJsonPath('data.readiness.score', fn ($score) => abs((float) $score - 88.0) < 0.001);

        $this->assertSame(2, DecisionSnapshot::where('student_profile_id', $profile->id)->count());
        $this->assertSame(2, ReadinessResult::where('student_profile_id', $profile->id)->count());
        $this->assertSame(6, SkillGapResult::whereIn(
            'decision_snapshot_id',
            DecisionSnapshot::where('student_profile_id', $profile->id)->pluck('id'),
        )->count());

        $this->assertDatabaseHas('readiness_results', [
            'id' => $firstReadiness->id,
            'score' => 72.50,
            'decision_snapshot_id' => $firstSnapshot->id,
        ]);

        $this->assertSame(72.50, (float) $firstReadiness->fresh()->score);
        $firstGaps->each(fn ($gap) => $this->assertDatabaseHas('skill_gap_results', [
            'id' => $gap->id,
            'decision_snapshot_id' => $firstSnapshot->id,
        ]));
    }

    public function test_latest_endpoint_returns_most_recent_successful_decision(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $this->fakeAllSuccess($profile, $role, $roleSkills, 60.0);
        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id]);

        $this->fakeAllSuccess($profile, $role, $roleSkills, 81.0);
        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id]);

        $response = $this->getJson('/api/v1/intelligence/latest?career_role_id='.$role->id);

        $response->assertOk()
            ->assertJsonPath('data.readiness.score', fn ($score) => abs((float) $score - 81.0) < 0.001)
            ->assertJsonPath('data.status', 'succeeded');

        $this->assertCount(3, $response->json('data.skill_gaps'));
    }

    public function test_latest_endpoint_404_when_no_decisions(): void
    {
        [$user] = $this->createScenario();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/intelligence/latest')
            ->assertStatus(404)
            ->assertJsonPath('code', 'DECISION_NOT_FOUND');
    }

    public function test_only_deployed_paths_are_called(): void
    {
        config(['services.data_science.url' => 'https://intelligence.test']);

        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $urls = [];
        Http::fake(function ($request) use (&$urls, $profile, $role, $roleSkills) {
            $urls[] = $request->url();

            return Http::response($this->skillGapResponse($profile, $role, $roleSkills), 200);
        });

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $this->assertCount(1, $urls, 'the readiness block must not cost a second round-trip');
        $this->assertSame('https://intelligence.test/api/v1/skill-gap', $urls[0]);

        $this->assertTrue(
            collect($urls)->every(fn ($url) => ! str_contains($url, '/intelligence/')),
            'no invented /api/v1/intelligence/* path may be called: it is not deployed',
        );
    }

    public function test_skill_gap_request_matches_the_deployed_contract(): void
    {
        config(['services.data_science.url' => 'https://intelligence.test']);

        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $sent = null;
        Http::fake(function ($request) use (&$sent, $profile, $role, $roleSkills) {
            $sent = $request->data();

            return Http::response($this->skillGapResponse($profile, $role, $roleSkills), 200);
        });

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $this->assertIsArray($sent);

        foreach ([
            'student_profile_id',
            'career_role_id',
            'career_role_version',
            'user_id',
            'target_role',
            'skills',
        ] as $field) {
            $this->assertArrayHasKey($field, $sent, "SkillGapRequest requires {$field}");
        }

        $this->assertSame((int) $profile->id, $sent['student_profile_id']);
        $this->assertSame((int) $role->id, $sent['career_role_id']);
        $this->assertSame((int) $role->version, $sent['career_role_version']);
        $this->assertSame('Data Analyst', $sent['target_role']);

        $this->assertArrayNotHasKey('learner', $sent);
        $this->assertArrayNotHasKey('role', $sent);

        $this->assertCount(3, $sent['skills']);

        $this->assertSame([
            'skill_id',
            'skill_name',
            'current_level',
            'required_level',
            'importance_weight',
            'is_critical',
        ], array_keys($sent['skills'][0]));

        $this->assertSame((int) $roleSkills[0]->skill_id, $sent['skills'][0]['skill_id']);
        $this->assertSame(2.5, (float) $sent['skills'][0]['current_level']);
        $this->assertSame(4.0, (float) $sent['skills'][0]['required_level']);
        $this->assertTrue((bool) $sent['skills'][0]['is_critical']);
    }

    public function test_correlation_ids_are_logged_for_the_skill_gap_call(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);
        $this->fakeAllSuccess($profile, $role, $roleSkills);

        Log::spy();

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'Intelligence service request completed')
                && ($context['operation'] ?? null) === 'skill_gap'
                && ($context['student_profile_id'] ?? null) === (int) $profile->id
                && ($context['career_role_id'] ?? null) === (int) $role->id
                && ($context['career_role_version'] ?? null) === (int) $role->version)
            ->once();
    }

    public function test_roadmap_versions_increment_and_previous_roadmap_is_superseded(): void
    {
        config(['services.data_science.roadmap_enabled' => true]);

        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        Http::fake([
            '*/api/v1/skill-gap' => Http::response($this->skillGapResponse($profile, $role, $roleSkills), 200),
            '*/api/v1/roadmap' => Http::response($this->roadmapResponse($profile, $role, $roleSkills), 200),
        ]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $roadmaps = Roadmap::query()
            ->where('student_profile_id', $profile->id)
            ->where('career_role_id', $role->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $roadmaps);
        $this->assertSame(1, (int) $roadmaps[0]->version);
        $this->assertSame(2, (int) $roadmaps[1]->version);
        $this->assertSame('superseded', $roadmaps[0]->status);
        $this->assertSame('active', $roadmaps[1]->status);

        $this->assertSame(2, RoadmapAction::where('roadmap_id', $roadmaps[0]->id)->count());
        $this->assertSame(2, RoadmapAction::where('roadmap_id', $roadmaps[1]->id)->count());

        $this->assertNotNull($roadmaps[1]->decision_snapshot_id);
        $this->assertSame('roadmap-v1', $roadmaps[1]->algorithm_version);
        $this->assertSame('roadmap-config-v1', $roadmaps[1]->configuration_version);
    }

    private function assertNothingPersisted(StudentProfile $profile, ?int $failedSnapshotId = null): void
    {
        $this->assertSame(0, ReadinessResult::where('student_profile_id', $profile->id)->count());
        $this->assertSame(0, SkillGapResult::count());

        if ($failedSnapshotId === null) {
            $this->assertSame(0, Roadmap::count());
        }
    }

    private array $scoreBox = ['value' => 72.5];

    private function fakeAllSuccess($profile, $role, $roleSkills, ?float $score = null): void
    {
        if ($score !== null) {
            $this->scoreBox['value'] = $score;
        }

        Http::fake([
            '*/api/v1/skill-gap' => function () use ($profile, $role, $roleSkills) {
                return Http::response(
                    $this->skillGapResponse($profile, $role, $roleSkills, $this->scoreBox['value']),
                    200,
                );
            },
        ]);
    }

    private function fakeSequence(?array $gap, ?array $readiness, ?array $roadmap): void
    {
        $fake = [];

        if ($gap !== null || $readiness !== null) {
            $fake['*/api/v1/skill-gap'] = Http::response(
                array_merge($gap ?? [], $readiness ?? []),
                200,
            );
        }

        if ($roadmap !== null) {
            $fake['*/api/v1/roadmap'] = Http::response($roadmap, 200);
        }

        Http::fake($fake);
    }

    private function skillGapResponse($profile, $role, $roleSkills, float $score = 72.5): array
    {
        $skillResults = [];

        foreach ($roleSkills as $roleSkill) {
            $evaluation = SkillEvaluation::where('student_profile_id', $profile->id)
                ->where('skill_id', $roleSkill->skill_id)
                ->orderByDesc('calculated_at')
                ->first();

            $current = (float) $evaluation->level;
            $required = (float) $roleSkill->required_level;
            $gap = max($required - $current, 0.0);

            $skillResults[] = [
                'skill_id' => (int) $roleSkill->skill_id,
                'skill_name' => $roleSkill->skill->name,
                'current_level' => $current,
                'required_level' => $required,
                'importance_weight' => (float) $roleSkill->importance_weight,
                'is_critical' => (bool) $roleSkill->is_critical,
                'gap' => $gap,
                'status' => $gap == 0.0 ? 'met' : 'gap',
                'confidence' => 90.0,
                'explanation' => 'gap explanation',
            ];
        }

        $totalSkills = count($skillResults);
        $metSkills = count(array_filter(
            $skillResults,
            static fn (array $result): bool => $result['status'] === 'met',
        ));

        return array_merge(
            [
                'student_profile_id' => (int) $profile->id,
                'career_role_id' => (int) $role->id,
                'career_role_version' => (int) $role->version,
                'algorithm_version' => 'intelligence-v1',
                'skill_results' => $skillResults,
            ],
            $this->readinessResponse($profile, $role, $score, $totalSkills, $metSkills),
        );
    }

    private function readinessResponse(
        $profile,
        $role,
        float $score = 72.5,
        int $totalSkills = 3,
        int $metSkills = 1,
    ): array {
        return [
            'student_profile_id' => (int) $profile->id,
            'career_role_id' => (int) $role->id,
            'career_role_version' => (int) $role->version,
            'algorithm_version' => 'intelligence-v1',
            'base_readiness_score' => $score,
            'readiness_score' => $score,
            'critical_skill_cap_applied' => false,
            'critical_skill_readiness_cap' => null,
            'critical_skill_gap_count' => 0,
            'critical_skill_names' => [],
            'total_skills' => $totalSkills,
            'met_skills' => $metSkills,
            'skills_with_gap' => $totalSkills - $metSkills,
        ];
    }

    private function roadmapResponse($profile, $role, $roleSkills): array
    {
        return [
            'student_profile_id' => (int) $profile->id,
            'career_role_id' => (int) $role->id,
            'career_role_version' => (int) $role->version,
            'algorithm_version' => 'roadmap-v1',
            'configuration_version' => 'roadmap-config-v1',

            'roadmap_version' => 1,
            'status' => 'active',
            'estimated_total_hours' => 10.0,
            'estimated_duration_weeks' => null,
            'limitations' => [],
            'phases' => [
                [
                    'phase' => 'foundations',
                    'order' => 1,
                    'actions' => [
                        [
                            'action_id' => 'A1',
                            'action_type' => 'resource',
                            'title' => 'Brush up SQL fundamentals',
                            'objective' => 'Refresh SQL fundamentals',
                            'target_skill_id' => (int) $roleSkills[0]->skill_id,
                            'target_skill_name' => (string) $roleSkills[0]->skill->name,
                            'priority_score' => 0.9,
                            'estimated_hours' => 6.0,
                            'estimated_duration_weeks' => 2,
                            'completion_criteria' => ['Complete the practice set'],
                            'explanation' => ['Critical skill with the largest gap'],
                        ],
                        [
                            'action_id' => 'A2',
                            'action_type' => 'practice',
                            'title' => 'Practice joins and aggregation',
                            'objective' => 'Practise joins and aggregation',
                            'target_skill_id' => (int) $roleSkills[1]->skill_id,
                            'target_skill_name' => (string) $roleSkills[1]->skill->name,
                            'prerequisite_skill_ids' => [(int) $roleSkills[0]->skill_id],
                            'priority_score' => 0.6,
                            'estimated_hours' => 4.0,
                            'estimated_duration_weeks' => 1,
                            'completion_criteria' => ['Complete the aggregation exercises'],
                            'explanation' => ['Critical skill reinforcing the foundation'],
                        ],
                    ],
                ],
            ],
            'next_best_action_id' => 'A1',
        ];
    }

    private function createScenario(string $status = 'approved'): array
    {
        $user = $this->createUserWithRole($this->learnerRole);

        $role = CareerRole::forceCreate([
            'title' => 'Data Analyst',
            'slug' => 'data-analyst-'.uniqid(),
            'version' => 1,
            'status' => $status,
        ]);

        $profile = StudentProfile::forceCreate([
            'user_id' => $user->id,
            'availability' => 'full_time',
            'primary_career_role_id' => $role->id,
        ]);

        $roleSkills = [];
        $matrix = [
            ['SQL', 4.0, 0.45, true, 2.5],
            ['Python', 4.0, 0.30, true, 3.5],
            ['Power BI', 4.0, 0.25, false, 4.0],
        ];

        foreach ($matrix as [$name, $required, $weight, $critical, $current]) {
            $skill = Skill::create(['name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid()]);

            $roleSkills[] = CareerRoleSkill::create([
                'career_role_id' => $role->id,
                'skill_id' => $skill->id,
                'required_level' => $required,
                'importance_weight' => $weight,
                'is_critical' => $critical,
            ]);

            SkillEvaluation::forceCreate([
                'student_profile_id' => $profile->id,
                'skill_id' => $skill->id,
                'level' => $current,
                'confidence' => 90,
                'algorithm_version' => 'baseline-v1',
                'calculated_at' => now()->addMicroseconds(rand(1, 500)),
            ]);
        }

        return [$user, $profile, $role, $roleSkills];
    }

    private function createUserWithRole(Role $role): User
    {
        $user = User::forceCreate([
            'name' => 'Learner',
            'email' => uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($role->id);

        return $user;
    }
}
