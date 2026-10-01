<?php

namespace Tests\Feature\Intelligence;

use App\Models\AlgorithmConfiguration;
use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\RoadmapAction;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Intelligence\IntelligenceClient;
use App\Services\Intelligence\IntelligencePayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Roadmap v1 effort/duration contract + request isolation.
 *
 * estimated_hours          = effort required to complete the action
 * estimated_duration_weeks = calendar duration in weeks (weekly availability)
 *
 * The two are distinct and are never substituted for one another; the
 * legacy `estimated_duration_hours` is not part of the contract. The
 * FastAPI Roadmap request is produced by IntelligenceClient::toRoadmapRequest()
 * and carries EXACTLY `learner` / `role` / `skills` — no internal-only
 * fields (skill_gap_result, evidence, versions, persistence metadata).
 */
class RoadmapEffortContractTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);

        config(['services.data_science.service_token' => 'test-service-token']);
        config(['services.data_science.roadmap_enabled' => true]);

        AlgorithmConfiguration::create([
            'name' => 'intelligence',
            'version' => 1,
            'status' => 'active',
            'config' => [],
            'activated_at' => now(),
        ]);
    }

    // ------------------------------------------------ Test 1

    public function test_valid_effort_and_duration_weeks_pass_validation(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $this->fakeCalculation($profile, $role, $roleSkills, [
            $this->action('A1', 'resource', 'Brush up SQL', (int) $roleSkills[0]->skill_id, 12.0, 3),
        ]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);
    }

    // ------------------------------------------------ Test 2

    public function test_legacy_estimated_duration_hours_without_weeks_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $action = $this->action('A1', 'resource', 'Legacy duration', (int) $roleSkills[0]->skill_id, 12.0, null);
        unset($action['estimated_duration_weeks']);
        $action['estimated_duration_hours'] = 12.0;

        $this->fakeCalculation($profile, $role, $roleSkills, [$action]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        $this->assertSame(0, RoadmapAction::count());
    }

    public function test_missing_estimated_hours_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $action = $this->action('A1', 'resource', 'No effort', (int) $roleSkills[0]->skill_id, null, 3);

        $this->fakeCalculation($profile, $role, $roleSkills, [$action]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');
    }

    public function test_non_positive_duration_weeks_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $this->fakeCalculation($profile, $role, $roleSkills, [
            $this->action('A1', 'resource', 'Zero weeks', (int) $roleSkills[0]->skill_id, 12.0, 0),
        ]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');
    }

    // ------------------------------------------------ Test 3

    public function test_effort_and_duration_weeks_are_persisted_verbatim(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $this->fakeCalculation($profile, $role, $roleSkills, [
            $this->action('A1', 'resource', 'Brush up SQL', (int) $roleSkills[0]->skill_id, 12.0, 3),
        ]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $action = RoadmapAction::query()->firstOrFail();

        $this->assertEqualsWithDelta(12.0, (float) $action->estimated_hours, 0.0001);
        $this->assertEqualsWithDelta(3.0, (float) $action->estimated_duration_weeks, 0.0001);

        // The legacy hours column is not written by the Roadmap v1 flow.
        $this->assertNull($action->estimated_duration_hours);
    }

    // ------------------------------------------------ Test 4

    public function test_resource_exposes_weeks_and_not_the_legacy_hours_field(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $this->fakeCalculation($profile, $role, $roleSkills, [
            $this->action('A1', 'resource', 'Brush up SQL', (int) $roleSkills[0]->skill_id, 12.0, 3),
        ]);

        $response = $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $response->assertJsonPath('data.roadmap.actions.0.estimated_hours', fn ($v) => abs((float) $v - 12.0) < 0.0001)
            ->assertJsonPath('data.roadmap.actions.0.estimated_duration_weeks', fn ($v) => abs((float) $v - 3.0) < 0.0001);

        $actionPayload = $response->json('data.roadmap.actions.0');

        $this->assertArrayHasKey('estimated_hours', $actionPayload);
        $this->assertArrayHasKey('estimated_duration_weeks', $actionPayload);
        $this->assertArrayNotHasKey('estimated_duration_hours', $actionPayload);
    }

    // ------------------------------------------------ Test 5

    public function test_to_roadmap_request_strips_internal_only_fields(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();

        $payload = $this->buildInternalPayload($profile, $role, $roleSkills);

        // The caller enriches the internal payload with the skill-gap result
        // (as IntelligenceService does) and the builder already carries the
        // per-skill evidence summary.
        $payload['skill_gap_result'] = ['skill_results' => [['skill_id' => 1]]];

        $request = app(IntelligenceClient::class)->toRoadmapRequest($payload);

        // The RoadmapRequest carries EXACTLY learner / role / skills.
        $this->assertSame(['learner', 'role', 'skills'], array_keys($request));

        // Internal-only / Laravel-owned fields never reach the wire.
        $this->assertArrayNotHasKey('skill_gap_result', $request);
        $this->assertArrayNotHasKey('algorithm_version', $request);
        $this->assertArrayNotHasKey('configuration_version', $request);
        $this->assertArrayNotHasKey('roadmap_version', $request);
        $this->assertArrayNotHasKey('status', $request);
        $this->assertArrayNotHasKey('decision_uuid', $request);
        $this->assertArrayNotHasKey('request_id', $request);

        $this->assertSame(
            ['student_profile_id', 'user_id', 'availability', 'weekly_availability_hours'],
            array_keys($request['learner']),
        );
        $this->assertSame(['id', 'title', 'version'], array_keys($request['role']));
        $this->assertSame(
            ['skill_id', 'skill_name', 'current_level', 'required_level', 'importance_weight', 'is_critical', 'confidence', 'prerequisite_skill_ids'],
            array_keys($request['skills'][0]),
        );

        // The per-skill internal evidence summary is not forwarded.
        $this->assertArrayNotHasKey('evidence', $request['skills'][0]);

        // Values are preserved (no invented fields, no rescaling).
        $this->assertSame((int) $profile->id, $request['learner']['student_profile_id']);
        $this->assertSame((int) $role->id, $request['role']['id']);
        $this->assertEqualsWithDelta(12.0, (float) $request['learner']['weekly_availability_hours'], 0.0001);
    }

    // ------------------------------------------------ Test 6

    public function test_roadmap_endpoint_receives_the_mapped_request_not_the_internal_payload(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $sent = null;

        Http::fake(function ($request) use (&$sent, $profile, $role, $roleSkills) {
            if (str_contains($request->url(), '/roadmap')) {
                $sent = $request->data();

                return Http::response($this->roadmapResponse($profile, $role, [
                    $this->action('A1', 'resource', 'Brush up SQL', (int) $roleSkills[0]->skill_id, 12.0, 3),
                ]), 200);
            }

            return Http::response($this->skillGapResponse($profile, $role, $roleSkills), 200);
        });

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $this->assertIsArray($sent, 'the roadmap endpoint must have been called');

        // It is NOT the internal payload: the RoadmapRequest carries only
        // learner / role / skills.
        $this->assertArrayNotHasKey('skill_gap_result', $sent);
        $this->assertArrayNotHasKey('evidence', $sent['skills'][0]);
        $this->assertArrayNotHasKey('algorithm_version', $sent);
        $this->assertArrayNotHasKey('configuration_version', $sent);
        $this->assertSame(['learner', 'role', 'skills'], array_keys($sent));

        // The roadmap input the algorithm needs is present.
        $this->assertSame((int) $profile->id, $sent['learner']['student_profile_id']);
        $this->assertSame((int) $role->version, $sent['role']['version']);
        $this->assertCount(4, $sent['skills']);
    }

    // ------------------------------------------------ Test 8

    public function test_confidence_is_forwarded_unchanged(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario(confidence: 85);
        Sanctum::actingAs($user);

        $sent = null;

        Http::fake(function ($request) use (&$sent, $profile, $role, $roleSkills) {
            if (str_contains($request->url(), '/roadmap')) {
                $sent = $request->data();

                return Http::response($this->roadmapResponse($profile, $role, [
                    $this->action('A1', 'resource', 'Brush up SQL', (int) $roleSkills[0]->skill_id, 12.0, 3),
                ]), 200);
            }

            return Http::response($this->skillGapResponse($profile, $role, $roleSkills), 200);
        });

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        // confidence stays on the 0..100 scale — never divided by 100.
        $this->assertEqualsWithDelta(85.0, (float) $sent['skills'][0]['confidence'], 0.0001);
        $this->assertNotEqualsWithDelta(0.85, (float) $sent['skills'][0]['confidence'], 0.0001);
    }

    // ------------------------------------------------ helpers

    private function buildInternalPayload(StudentProfile $profile, CareerRole $role, Collection $roleSkills): array
    {
        $evaluations = SkillEvaluation::query()
            ->where('student_profile_id', $profile->id)
            ->get();

        $roleSkills->each(fn (CareerRoleSkill $roleSkill) => $roleSkill->load(['skill', 'prerequisites']));

        $evidenceSummary = [];
        foreach ($roleSkills as $roleSkill) {
            $evidenceSummary[(int) $roleSkill->skill_id] = ['total' => 2, 'verified' => 1];
        }

        return (new IntelligencePayloadBuilder)->build(
            $profile->fresh(),
            $role,
            $roleSkills,
            $evaluations,
            $evidenceSummary,
            'skill-gap-v1',
            'config-v1',
        );
    }

    private function fakeCalculation($profile, $role, $roleSkills, array $actions): void
    {
        Http::fake(function ($request) use ($profile, $role, $roleSkills, $actions) {
            if (str_contains($request->url(), '/roadmap')) {
                return Http::response($this->roadmapResponse($profile, $role, $actions), 200);
            }

            return Http::response($this->skillGapResponse($profile, $role, $roleSkills), 200);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function action(string $id, string $type, string $title, int $targetSkillId, ?float $hours, ?int $weeks): array
    {
        $action = [
            'action_id' => $id,
            'action_type' => $type,
            'title' => $title,
            // Roadmap v1 action contract — every field below is REQUIRED.
            'objective' => 'Objective for '.$title,
            'target_skill_id' => $targetSkillId,
            // The CANONICAL name of that skill — it must agree with the id.
            'target_skill_name' => (string) Skill::query()->findOrFail($targetSkillId)->name,
            'priority_score' => 0.5,
            // Roadmap v1: lists of strings, never a plain string.
            'completion_criteria' => ['Completion criteria for '.$title],
            'explanation' => ['Explanation for '.$title],
        ];

        if ($hours !== null) {
            $action['estimated_hours'] = $hours;
        }

        if ($weeks !== null) {
            $action['estimated_duration_weeks'] = $weeks;
        }

        return $action;
    }

    private function roadmapResponse($profile, $role, array $actions): array
    {
        return [
            'student_profile_id' => (int) $profile->id,
            'career_role_id' => (int) $role->id,
            'career_role_version' => (int) $role->version,
            'algorithm_version' => 'roadmap-v1',
            'configuration_version' => 'roadmap-config-v1',
            'roadmap_version' => 1,
            'status' => 'active',
            // Roadmap v1 totals + limitations — required by the contract.
            // The learner HAS weekly availability here (12), so the calendar
            // duration must be a positive integer.
            'estimated_total_hours' => 12.0,
            'estimated_duration_weeks' => 3,
            'limitations' => [],
            'phases' => [
                ['phase' => 'foundations', 'actions' => $actions],
            ],
            'next_best_action_id' => $actions[0]['action_id'] ?? null,
        ];
    }

    private function skillGapResponse($profile, $role, $roleSkills, float $score = 72.5): array
    {
        $skillResults = [];

        foreach ($roleSkills as $roleSkill) {
            $current = (float) SkillEvaluation::where('student_profile_id', $profile->id)
                ->where('skill_id', $roleSkill->skill_id)
                ->orderByDesc('id')
                ->first()
                ->level;

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
            ];
        }

        $metSkills = count(array_filter(
            $skillResults,
            static fn (array $result): bool => $result['status'] === 'met',
        ));

        return [
            'student_profile_id' => (int) $profile->id,
            'career_role_id' => (int) $role->id,
            'career_role_version' => (int) $role->version,
            'algorithm_version' => 'intelligence-v1',
            'configuration_version' => 'config-v1',
            'skill_results' => $skillResults,
            'base_readiness_score' => $score,
            'readiness_score' => $score,
            'critical_skill_cap_applied' => false,
            'critical_skill_gap_count' => 0,
            'critical_skill_names' => [],
            'total_skills' => count($skillResults),
            'met_skills' => $metSkills,
            'skills_with_gap' => count($skillResults) - $metSkills,
        ];
    }

    /**
     * @return array{0: User, 1: StudentProfile, 2: CareerRole, 3: Collection<int, CareerRoleSkill>}
     */
    private function createScenario(int $confidence = 90): array
    {
        $user = User::forceCreate([
            'name' => 'Learner',
            'email' => uniqid().'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($this->learnerRole->id);

        $role = CareerRole::forceCreate([
            'title' => 'Data Analyst',
            'slug' => 'data-analyst-'.uniqid(),
            'version' => 1,
            'status' => 'approved',
        ]);

        $profile = StudentProfile::forceCreate([
            'user_id' => $user->id,
            'availability' => 'evenings',
            'weekly_availability_hours' => 12,
            'primary_career_role_id' => $role->id,
        ]);

        $roleSkills = collect();

        foreach ([
            ['SQL', 4.0, 0.40, true, 2.5],
            ['Python', 4.0, 0.30, true, 3.5],
            ['Power BI', 4.0, 0.20, false, 4.0],
            ['Statistics', 4.0, 0.10, false, 2.0],
        ] as [$name, $required, $weight, $critical, $current]) {
            $skill = Skill::create([
                'name' => $name,
                'slug' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(),
            ]);

            $roleSkills->push(CareerRoleSkill::create([
                'career_role_id' => $role->id,
                'skill_id' => $skill->id,
                'required_level' => $required,
                'importance_weight' => $weight,
                'is_critical' => $critical,
            ]));

            SkillEvaluation::forceCreate([
                'student_profile_id' => $profile->id,
                'skill_id' => $skill->id,
                'level' => $current,
                'confidence' => $confidence,
                'algorithm_version' => 'baseline-v1',
                'calculated_at' => now()->addMicroseconds(rand(1, 500)),
            ]);
        }

        return [$user, $profile, $role, $roleSkills];
    }
}
