<?php

namespace Tests\Feature\Intelligence;

use App\Models\AlgorithmConfiguration;
use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\Roadmap;
use App\Models\RoadmapAction;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Roadmap v1 integration — the CLOSED action_type enum and the
 * prerequisite_skill_ids contract. Both must fail loudly on a FastAPI
 * contract violation: no silent coercion to "practice", no silently
 * ignored / collapsed prerequisites.
 */
class RoadmapActionContractTest extends TestCase
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

    // ------------------------------------------------ action_type (A)

    public function test_unknown_action_type_is_rejected_instead_of_falling_back_to_practice(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $this->fakeCalculation($profile, $role, $roleSkills, [
            $this->action('A1', 'learning_resource', 'Brush up SQL', (int) $roleSkills[0]->skill_id),
        ]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        // The invalid type must NOT have been coerced to a stored action.
        $this->assertSame(0, Roadmap::count());
        $this->assertSame(0, RoadmapAction::count());
    }

    public function test_typo_action_type_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $this->fakeCalculation($profile, $role, $roleSkills, [
            $this->action('A1', 'practcie', 'Typo action', (int) $roleSkills[0]->skill_id),
        ]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        $this->assertSame(0, RoadmapAction::count());
    }

    public function test_non_string_action_type_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $action = $this->action('A1', 'resource', 'Numeric type', (int) $roleSkills[0]->skill_id);
        $action['action_type'] = 3;

        $this->fakeCalculation($profile, $role, $roleSkills, [$action]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');
    }

    // ------------------------------------------------ action_type (B)

    public function test_every_allowed_action_type_is_accepted_and_persisted(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $allowed = RoadmapAction::TYPES;
        $actions = [];

        foreach ($allowed as $index => $type) {
            $actions[] = $this->action(
                'A'.($index + 1),
                $type,
                'Action '.$type,
                (int) $roleSkills[$index % $roleSkills->count()]->skill_id,
            );
        }

        $this->fakeCalculation($profile, $role, $roleSkills, $actions);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $persistedTypes = RoadmapAction::query()
            ->orderBy('order_index')
            ->pluck('type')
            ->all();

        $this->assertSame($allowed, $persistedTypes);
        $this->assertSame(count($allowed), RoadmapAction::count());
    }

    // ------------------------------------------------ prerequisites (D/E/F/G/H/I/J)

    public function test_prerequisite_skill_ids_as_string_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $this->fakeCalculation($profile, $role, $roleSkills, [
            $this->action('A1', 'practice', 'String prerequisites', (int) $roleSkills[0]->skill_id, '2,5,8'),
        ]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        $this->assertSame(0, RoadmapAction::count());
    }

    public function test_prerequisite_skill_ids_as_scalar_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $this->fakeCalculation($profile, $role, $roleSkills, [
            $this->action('A1', 'practice', 'Scalar prerequisites', (int) $roleSkills[0]->skill_id, 2),
        ]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        $this->assertSame(0, RoadmapAction::count());
    }

    public function test_duplicate_prerequisite_skill_ids_are_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $duplicate = (int) $roleSkills[1]->skill_id;

        $this->fakeCalculation($profile, $role, $roleSkills, [
            $this->action('A1', 'practice', 'Duplicate prerequisites', (int) $roleSkills[0]->skill_id, [
                $duplicate,
                $duplicate,
                (int) $roleSkills[2]->skill_id,
            ]),
        ]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        $this->assertSame(0, RoadmapAction::count());
    }

    public function test_non_integer_prerequisite_skill_id_element_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $this->fakeCalculation($profile, $role, $roleSkills, [
            $this->action('A1', 'practice', 'String element', (int) $roleSkills[0]->skill_id, ['2']),
        ]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        $this->assertSame(0, RoadmapAction::count());
    }

    public function test_unknown_prerequisite_skill_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $this->fakeCalculation($profile, $role, $roleSkills, [
            $this->action('A1', 'practice', 'Unknown prerequisite', (int) $roleSkills[0]->skill_id, [999999]),
        ]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        $this->assertSame(0, RoadmapAction::count());
    }

    public function test_empty_prerequisite_skill_ids_array_is_accepted(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $this->fakeCalculation($profile, $role, $roleSkills, [
            $this->action('A1', 'resource', 'No prerequisites', (int) $roleSkills[0]->skill_id, []),
        ]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $action = RoadmapAction::query()->firstOrFail();
        $this->assertSame([], $action->prerequisites->pluck('id')->all());
        $this->assertNull($action->prerequisite_skill_id);
    }

    public function test_null_prerequisite_skill_ids_is_treated_as_no_prerequisites(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        // The key is explicitly PRESENT and null (not merely absent).
        $action = $this->action('A1', 'resource', 'Null prerequisites', (int) $roleSkills[0]->skill_id);
        $action['prerequisite_skill_ids'] = null;

        $this->fakeCalculation($profile, $role, $roleSkills, [$action]);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $persisted = RoadmapAction::query()->firstOrFail();
        $this->assertSame([], $persisted->prerequisites->pluck('id')->all());
    }

    // ------------------------------------------------ resource versions (G/H/5)

    public function test_roadmap_resource_exposes_the_roadmaps_own_versions_without_mixing(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $this->fakeCalculation($profile, $role, $roleSkills, [
            $this->action('A1', 'resource', 'Brush up SQL', (int) $roleSkills[0]->skill_id),
        ]);

        $response = $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        // The roadmap block carries the ROADMAP's own versions...
        $response->assertJsonPath('data.roadmap.algorithm_version', 'roadmap-v1')
            ->assertJsonPath('data.roadmap.configuration_version', 'roadmap-config-v1');

        // ...while the top-level versions remain the skill-gap/readiness
        // ones (they are a different calculation and must not be mixed).
        $response->assertJsonPath('data.algorithm_version', 'intelligence-v1')
            ->assertJsonPath('data.configuration_version', 'config-v1');
    }

    // ------------------------------------------------ version concurrency (J)

    public function test_roadmap_version_is_unique_per_learner_and_role(): void
    {
        [$user, $profile, $role] = $this->createScenario();

        $attributes = [
            'student_profile_id' => $profile->id,
            'career_role_id' => $role->id,
            'career_role_version' => 1,
            'version' => 1,
            'status' => Roadmap::STATUS_ACTIVE,
            'generated_at' => now(),
        ];

        Roadmap::create($attributes);

        // The unique constraint turns a concurrent duplicate-version write
        // into an atomic failure instead of two roadmaps sharing a version.
        $this->expectException(QueryException::class);

        Roadmap::create($attributes);
    }

    // ------------------------------------------------ helpers

    /**
     * @param  list<array<string, mixed>>  $actions
     */
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
    private function action(string $id, string $type, string $title, int $targetSkillId, mixed $prerequisites = null): array
    {
        $action = [
            'action_id' => $id,
            'action_type' => $type,
            'title' => $title,
            'target_skill_id' => $targetSkillId,
            // Roadmap v1 effort/duration contract — required on every action.
            'estimated_hours' => 12.0,
            'estimated_duration_weeks' => 3.0,
        ];

        if ($prerequisites !== null) {
            $action['prerequisite_skill_ids'] = $prerequisites;
        }

        return $action;
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
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
            ])->load('skill'));

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
}
