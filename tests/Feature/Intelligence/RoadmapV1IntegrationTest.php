<?php

namespace Tests\Feature\Intelligence;

use App\Models\AlgorithmConfiguration;
use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\DecisionSnapshot;
use App\Models\Roadmap;
use App\Models\RoadmapAction;
use App\Models\RoadmapActionPrerequisite;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Intelligence\IntelligencePayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Roadmap v1 integration — the Laravel side of the FastAPI Roadmap v1
 * contract: multiple prerequisites (payload + persistence), the persisted
 * Next Best Action, version ownership, and weekly availability hours.
 */
class RoadmapV1IntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);

        config(['services.data_science.service_token' => 'test-service-token']);

        AlgorithmConfiguration::create([
            'name' => 'intelligence',
            'version' => 1,
            'status' => 'active',
            'config' => [],
            'activated_at' => now(),
        ]);
    }

    // ------------------------------------------------ TEST A

    public function test_payload_carries_all_prerequisite_skill_ids(): void
    {
        [$user, $profile, $role, $roleSkills, $skills] = $this->createScenario();

        // SQL (target) depends on Python, Power BI and Statistics.
        $sql = $roleSkills[0];
        $sql->prerequisites()->sync([
            $skills[1]->id,
            $skills[2]->id,
            $skills[3]->id,
        ]);

        $payload = $this->buildPayload($profile, $role, $roleSkills);

        $sqlPayload = collect($payload['skills'])->firstWhere('skill_id', (int) $sql->skill_id);

        $this->assertSame(
            [$skills[1]->id, $skills[2]->id, $skills[3]->id],
            $sqlPayload['prerequisite_skill_ids'],
        );

        // A skill without prerequisites yields an EMPTY array, never null.
        $pythonPayload = collect($payload['skills'])->firstWhere('skill_id', (int) $roleSkills[1]->skill_id);
        $this->assertSame([], $pythonPayload['prerequisite_skill_ids']);
    }

    public function test_payload_prerequisite_ids_are_deduplicated_integers(): void
    {
        [$user, $profile, $role, $roleSkills, $skills] = $this->createScenario();

        // A duplicate dependency row is impossible (unique pair), but the
        // legacy fallback must never double up with the relation either.
        $roleSkills[0]->prerequisites()->sync([$skills[1]->id]);

        $payload = $this->buildPayload($profile, $role, $roleSkills);
        $sqlPayload = collect($payload['skills'])->firstWhere('skill_id', (int) $roleSkills[0]->skill_id);

        $this->assertSame([$skills[1]->id], $sqlPayload['prerequisite_skill_ids']);
        $this->assertSame(
            $sqlPayload['prerequisite_skill_ids'],
            array_values(array_unique($sqlPayload['prerequisite_skill_ids'])),
        );
    }

    public function test_payload_falls_back_to_legacy_single_prerequisite_when_relation_is_empty(): void
    {
        [$user, $profile, $role, $roleSkills, $skills] = $this->createScenario();

        // Legacy data: only the single column is populated.
        $roleSkills[0]->forceFill(['prerequisite_skill_id' => $skills[3]->id])->save();

        $payload = $this->buildPayload($profile, $role, $roleSkills);
        $sqlPayload = collect($payload['skills'])->firstWhere('skill_id', (int) $roleSkills[0]->skill_id);

        $this->assertSame([$skills[3]->id], $sqlPayload['prerequisite_skill_ids']);
    }

    // ------------------------------------------------ TEST B

    public function test_roadmap_action_persists_every_prerequisite_skill(): void
    {
        config(['services.data_science.roadmap_enabled' => true]);

        [$user, $profile, $role, $roleSkills, $skills] = $this->createScenario();
        Sanctum::actingAs($user);

        $prerequisiteIds = [(int) $skills[0]->id, (int) $skills[2]->id, (int) $skills[3]->id];

        $this->fakeCalculation($profile, $role, $roleSkills, $skills, [
            'python_prerequisites' => $prerequisiteIds,
        ]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $pythonAction = RoadmapAction::query()->where('title', 'Practice Python')->firstOrFail();

        $this->assertSame(
            $prerequisiteIds,
            $pythonAction->prerequisites->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
        );

        // The legacy single column keeps the FIRST prerequisite only.
        $this->assertSame($prerequisiteIds[0], (int) $pythonAction->prerequisite_skill_id);

        // Every prerequisite is stored relationally, none lost.
        $this->assertSame(3, RoadmapActionPrerequisite::where('roadmap_action_id', $pythonAction->id)->count());

        // And the API exposes the full set.
        $response = $this->getJson('/api/v1/intelligence/latest?career_role_id='.$role->id)->assertOk();
        $actions = collect($response->json('data.roadmap.actions'));
        $pythonPayload = $actions->firstWhere('title', 'Practice Python');
        $this->assertSame(
            $prerequisiteIds,
            collect($pythonPayload['prerequisite_skill_ids'])->sort()->values()->all(),
        );
    }

    // ------------------------------------------------ TEST C

    public function test_next_best_action_id_is_persisted_and_points_inside_the_same_roadmap(): void
    {
        config(['services.data_science.roadmap_enabled' => true]);

        [$user, $profile, $role, $roleSkills, $skills] = $this->createScenario();
        Sanctum::actingAs($user);

        // FastAPI nominates A2 (the SECOND action), not the first one.
        $this->fakeCalculation($profile, $role, $roleSkills, $skills, [
            'next_best_action_id' => 'A2',
        ]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->assertJsonPath('data.roadmap.next_best_action_id', fn ($value) => $value !== null);

        $roadmap = Roadmap::query()->where('student_profile_id', $profile->id)->firstOrFail();
        $pythonAction = RoadmapAction::query()->where('title', 'Practice Python')->firstOrFail();

        $this->assertNotNull($roadmap->next_best_action_id);
        $this->assertSame((int) $pythonAction->id, (int) $roadmap->next_best_action_id);
        // The action belongs to the SAME roadmap.
        $this->assertSame((int) $roadmap->id, (int) $pythonAction->roadmap_id);

        $this->getJson('/api/v1/intelligence/latest?career_role_id='.$role->id)
            ->assertOk()
            ->assertJsonPath('data.roadmap.next_best_action_id', (int) $pythonAction->id);
    }

    public function test_next_best_action_id_that_does_not_match_a_returned_action_is_rejected(): void
    {
        config(['services.data_science.roadmap_enabled' => true]);

        [$user, $profile, $role, $roleSkills, $skills] = $this->createScenario();
        Sanctum::actingAs($user);

        $this->fakeCalculation($profile, $role, $roleSkills, $skills, [
            'next_best_action_id' => 'A-DOES-NOT-EXIST',
        ]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        $this->assertSame(0, Roadmap::count());
        $this->assertSame(0, RoadmapAction::count());
    }

    // ------------------------------------------------ TEST D

    public function test_roadmap_stores_the_roadmaps_own_algorithm_and_configuration_versions(): void
    {
        config(['services.data_science.roadmap_enabled' => true]);

        [$user, $profile, $role, $roleSkills, $skills] = $this->createScenario();
        Sanctum::actingAs($user);

        // The skill-gap response reports 'intelligence-v1' / 'config-v1';
        // the ROADMAP reports different, roadmap-specific versions. The
        // roadmap must keep its OWN values.
        $this->fakeCalculation($profile, $role, $roleSkills, $skills, []);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $roadmap = Roadmap::query()->where('student_profile_id', $profile->id)->firstOrFail();

        $this->assertSame('roadmap-v1', $roadmap->algorithm_version);
        $this->assertSame('roadmap-config-v1', $roadmap->configuration_version);
    }

    // ------------------------------------------------ TEST E

    public function test_roadmap_version_and_status_are_laravel_owned(): void
    {
        config(['services.data_science.roadmap_enabled' => true]);

        [$user, $profile, $role, $roleSkills, $skills] = $this->createScenario();
        Sanctum::actingAs($user);

        // FastAPI echoes the SAME roadmap_version=1 on both calls.
        $this->fakeCalculation($profile, $role, $roleSkills, $skills, []);
        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])->assertStatus(201);

        $this->fakeCalculation($profile, $role, $roleSkills, $skills, []);
        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])->assertStatus(201);

        $roadmaps = Roadmap::query()
            ->where('student_profile_id', $profile->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $roadmaps);
        $this->assertSame(1, (int) $roadmaps[0]->version);
        $this->assertSame(Roadmap::STATUS_SUPERSEDED, $roadmaps[0]->status);
        $this->assertSame(2, (int) $roadmaps[1]->version);
        $this->assertSame(Roadmap::STATUS_ACTIVE, $roadmaps[1]->status);
    }

    // ------------------------------------------------ TEST F / G

    public function test_weekly_availability_hours_is_exposed_in_the_intelligence_snapshot(): void
    {
        [$user, $profile, $role, $roleSkills, $skills] = $this->createScenario();
        $profile->forceFill(['weekly_availability_hours' => 15])->save();
        Sanctum::actingAs($user);

        Http::fake([
            '*/api/v1/skill-gap' => Http::response($this->skillGapResponse($profile, $role, $roleSkills), 200),
        ]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $snapshot = DecisionSnapshot::where('student_profile_id', $profile->id)->firstOrFail();
        $weeklyHours = $snapshot->snapshot['learner']['weekly_availability_hours'];

        $this->assertIsNumeric($weeklyHours);
        $this->assertEqualsWithDelta(15.0, (float) $weeklyHours, 0.0001);

        // availability is untouched and still present alongside it.
        $this->assertSame($profile->availability, $snapshot->snapshot['learner']['availability']);
    }

    public function test_null_weekly_availability_hours_does_not_break_the_payload(): void
    {
        [$user, $profile, $role, $roleSkills, $skills] = $this->createScenario();
        // Explicitly null.
        $profile->forceFill(['weekly_availability_hours' => null])->save();
        Sanctum::actingAs($user);

        Http::fake([
            '*/api/v1/skill-gap' => Http::response($this->skillGapResponse($profile, $role, $roleSkills), 200),
        ]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $snapshot = DecisionSnapshot::where('student_profile_id', $profile->id)->firstOrFail();

        // Null is preserved — never coerced to 0.
        $this->assertArrayHasKey('weekly_availability_hours', $snapshot->snapshot['learner']);
        $this->assertNull($snapshot->snapshot['learner']['weekly_availability_hours']);
    }

    // ------------------------------------------------ helpers

    private function buildPayload(StudentProfile $profile, CareerRole $role, Collection $roleSkills): array
    {
        $evaluations = SkillEvaluation::query()
            ->where('student_profile_id', $profile->id)
            ->get();

        $roleSkills->each(fn (CareerRoleSkill $roleSkill) => $roleSkill->load(['skill', 'prerequisites']));

        return (new IntelligencePayloadBuilder)->build(
            $profile->fresh(),
            $role,
            $roleSkills,
            $evaluations,
            [],
            'skill-gap-v1',
            'config-v1',
        );
    }

    /**
     * @param  array<string, mixed>  $roadmapOverrides
     */
    private function fakeCalculation($profile, $role, $roleSkills, $skills, array $roadmapOverrides): void
    {
        $nextBestActionId = $roadmapOverrides['next_best_action_id'] ?? 'A1';
        $pythonPrerequisites = $roadmapOverrides['python_prerequisites'] ?? [];

        Http::fake(function ($request) use ($profile, $role, $roleSkills, $skills, $nextBestActionId, $pythonPrerequisites) {
            if (str_contains($request->url(), '/roadmap')) {
                return Http::response($this->roadmapResponse(
                    $profile,
                    $role,
                    $roleSkills,
                    $skills,
                    $nextBestActionId,
                    $pythonPrerequisites,
                ), 200);
            }

            return Http::response($this->skillGapResponse($profile, $role, $roleSkills), 200);
        });
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
            // Skill-gap reports its OWN versions; the roadmap reports
            // different ones (see roadmapResponse).
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
     * @param  list<int>  $pythonPrerequisites
     */
    private function roadmapResponse($profile, $role, $roleSkills, $skills, string $nextBestActionId, array $pythonPrerequisites): array
    {
        return [
            'student_profile_id' => (int) $profile->id,
            'career_role_id' => (int) $role->id,
            'career_role_version' => (int) $role->version,
            'algorithm_version' => 'roadmap-v1',
            'configuration_version' => 'roadmap-config-v1',
            'roadmap_version' => 1,
            'status' => 'active',
            // Roadmap v1 totals + limitations. This learner has NO weekly
            // availability, so a null calendar duration is valid.
            'estimated_total_hours' => 10.0,
            'estimated_duration_weeks' => null,
            'limitations' => ['market_demand_factor_unavailable_neutral_1_0'],
            'phases' => [
                [
                    'phase' => 'foundations',
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
                            'completion_criteria' => 'Complete the SQL refresher',
                            'explanation' => 'Foundational skill with the largest gap',
                        ],
                        [
                            'action_id' => 'A2',
                            'action_type' => 'practice',
                            'title' => 'Practice Python',
                            'objective' => 'Practise Python problem solving',
                            'target_skill_id' => (int) $roleSkills[1]->skill_id,
                            'target_skill_name' => (string) $roleSkills[1]->skill->name,
                            'prerequisite_skill_ids' => $pythonPrerequisites,
                            'priority_score' => 0.6,
                            'estimated_hours' => 4.0,
                            'estimated_duration_weeks' => 1,
                            'completion_criteria' => 'Complete the Python exercises',
                            'explanation' => 'Critical skill reinforcing the foundation',
                        ],
                    ],
                ],
            ],
            'next_best_action_id' => $nextBestActionId,
        ];
    }

    /**
     * @return array{0: User, 1: StudentProfile, 2: CareerRole, 3: Collection<int, CareerRoleSkill>, 4: list<Skill>}
     */
    private function createScenario(): array
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
            'primary_career_role_id' => $role->id,
        ]);

        $roleSkills = collect();
        $skills = [];

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
            $skills[] = $skill;

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
                'confidence' => 90,
                'algorithm_version' => 'baseline-v1',
                'calculated_at' => now()->addMicroseconds(rand(1, 500)),
            ]);
        }

        return [$user, $profile, $role, $roleSkills, $skills];
    }
}
