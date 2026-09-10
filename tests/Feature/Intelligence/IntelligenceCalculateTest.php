<?php

namespace Tests\Feature\Intelligence;

use App\Models\AlgorithmConfiguration;
use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\DecisionSnapshot;
use App\Models\ReadinessResult;
use App\Models\Roadmap;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\SkillGapResult;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * US-INT-01 — the atomic intelligence decision endpoint
 * (POST /api/v1/intelligence/calculate): authorization, snapshot
 * creation, version capture, response validation, no-fabrication on
 * failure, historical preservation, and recalculation hooks.
 */
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

        AlgorithmConfiguration::create([
            'name' => 'intelligence',
            'version' => 1,
            'status' => 'active',
            'config' => ['skill_scale' => [0, 5]],
            'activated_at' => now(),
        ]);
    }

    // ------------------------------------------------ authorization

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

    // ------------------------------------------------ career role

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

    // ------------------------------------------------ snapshot + success

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

        // Snapshot captures the pre-call input state (§6).
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

        // Response carries permitted fields only.
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

            // First call: skill-gap; second call: readiness.
            return Http::response(
                $calls === 1
                    ? $this->skillGapResponse($profile, $role, $roleSkills)
                    : $this->readinessResponse($profile, $role),
                200,
            );
        });

        $this->postJson(
            '/api/v1/intelligence/calculate',
            ['career_role_id' => $role->id],
            ['X-Request-ID' => 'my-correlation-id'],
        )->assertStatus(201);

        $this->assertSame(2, $calls);

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

    // ------------------------------------------------ response validation

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

        $this->fakeSequence($gap, $this->readinessResponse($profile, $role), null);

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

    // ------------------------------------------------ failure handling

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

    // ------------------------------------------------ history

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

        // Second calculation with a different score.
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

        // Decision 101 is immutable after decision 102 exists (§21).
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

    public function test_versioned_paths_are_used(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $urls = [];
        Http::fake(function ($request) use (&$urls, $profile, $role, $roleSkills) {
            $urls[] = $request->url();

            $isGap = str_contains($request->url(), '/skill-gap');

            return Http::response(
                $isGap
                    ? $this->skillGapResponse($profile, $role, $roleSkills)
                    : $this->readinessResponse($profile, $role),
                200,
            );
        });

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id]);

        $this->assertNotEmpty($urls);
        $this->assertTrue(
            collect($urls)->contains(fn ($url) => str_contains($url, '/api/v1/intelligence/skill-gap')),
            'the versioned SRS skill-gap path must be used',
        );
        $this->assertTrue(
            collect($urls)->contains(fn ($url) => str_contains($url, '/api/v1/intelligence/readiness')),
            'the versioned SRS readiness path must be used',
        );
    }

    // ------------------------------------------------ helpers

    private function assertNothingPersisted(StudentProfile $profile, ?int $failedSnapshotId = null): void
    {
        $this->assertSame(0, ReadinessResult::where('student_profile_id', $profile->id)->count());
        $this->assertSame(0, SkillGapResult::count());

        if ($failedSnapshotId === null) {
            $this->assertSame(0, Roadmap::count());
        }
    }

    /**
     * Shared, mutable score box for URL-pattern fakes: a test can call
     * fakeAllSuccess() twice with different scores — the readiness stub
     * reads the CURRENT box value at request time, so re-faking is not
     * required (replacing a registered Http::fake mid-test does not
     * always override earlier URL stubs).
     */
    private array $scoreBox = ['value' => 72.5];

    private function fakeAllSuccess($profile, $role, $roleSkills, ?float $score = null): void
    {
        if ($score !== null) {
            $this->scoreBox['value'] = $score;
        }

        $gap = $this->skillGapResponse($profile, $role, $roleSkills);
        $profileId = $profile->id;
        $roleId = $role->id;
        $roleVersion = (int) $role->version;

        Http::fake([
            '*/intelligence/skill-gap' => Http::response($gap, 200),
            '*/intelligence/readiness' => function () use ($profileId, $roleId, $roleVersion) {
                $score = $this->scoreBox['value'];

                return Http::response([
                    'student_profile_id' => $profileId,
                    'career_role_id' => $roleId,
                    'career_role_version' => $roleVersion,
                    'algorithm_version' => 'intelligence-v1',
                    'base_readiness_score' => $score,
                    'readiness_score' => $score,
                    'critical_skill_cap_applied' => false,
                    'critical_skill_gap_count' => 0,
                    'critical_skill_names' => [],
                    'total_skills' => 3,
                    'met_skills' => 1,
                    'skills_with_gap' => 2,
                ], 200);
            },
        ]);
    }

    private function fakeSequence(?array $gap, ?array $readiness, ?array $roadmap): void
    {
        // URL-pattern fakes: each intelligence endpoint gets its own
        // response, so repeated calculations stay deterministic even
        // when the same flow runs twice in one test.
        $fake = [];

        if ($gap !== null) {
            $fake['*/intelligence/skill-gap'] = Http::response($gap, 200);
        }

        if ($readiness !== null) {
            $fake['*/intelligence/readiness'] = Http::response($readiness, 200);
        }

        if ($roadmap !== null) {
            $fake['*/intelligence/roadmap'] = Http::response($roadmap, 200);
        }

        Http::fake($fake);
    }

    private function skillGapResponse($profile, $role, $roleSkills): array
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

        return [
            'student_profile_id' => (int) $profile->id,
            'career_role_id' => (int) $role->id,
            'career_role_version' => (int) $role->version,
            'algorithm_version' => 'intelligence-v1',
            'skill_results' => $skillResults,
        ];
    }

    private function readinessResponse($profile, $role, float $score = 72.5): array
    {
        return [
            'student_profile_id' => (int) $profile->id,
            'career_role_id' => (int) $role->id,
            'career_role_version' => (int) $role->version,
            'algorithm_version' => 'intelligence-v1',
            'base_readiness_score' => $score,
            'readiness_score' => $score,
            'critical_skill_cap_applied' => false,
            'critical_skill_gap_count' => 0,
            'critical_skill_names' => [],
            'total_skills' => 3,
            'met_skills' => 1,
            'skills_with_gap' => 2,
        ];
    }

    /**
     * @return array{0: User, 1: StudentProfile, 2: CareerRole, 3: list<CareerRoleSkill>}
     */
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
