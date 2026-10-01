<?php

namespace Tests\Feature\Intelligence;

use App\Exceptions\IntelligenceException;
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
use App\Services\Intelligence\IntelligencePersistenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\TestCase;

/**
 * FastAPI RoadmapPhase contract hardening.
 *
 * `phase` is a CLOSED enum (foundations / core_skills / applied_practice /
 * career_readiness) and each phase carries its canonical `order`
 * (1..4). An unknown phase, a missing/non-integer/out-of-range order, or an
 * order that disagrees with its phase is a contract violation — there is no
 * fallback and no auto-correction anywhere, including mapPhase().
 */
class RoadmapPhaseContractTest extends TestCase
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

    // ------------------------------------------------ A. valid phases + order

    public function test_foundations_phase_with_order_one_is_accepted(): void
    {
        $this->assertPhaseAccepted('foundations', 1);
    }

    public function test_core_skills_phase_with_order_two_is_accepted(): void
    {
        $this->assertPhaseAccepted('core_skills', 2);
    }

    public function test_applied_practice_phase_with_order_three_is_accepted(): void
    {
        $this->assertPhaseAccepted('applied_practice', 3);
    }

    public function test_career_readiness_phase_with_order_four_is_accepted(): void
    {
        $this->assertPhaseAccepted('career_readiness', 4);
    }

    public function test_the_canonical_phase_order_mapping_is_exactly_one_to_four(): void
    {
        $this->assertSame([
            'foundations' => 1,
            'core_skills' => 2,
            'applied_practice' => 3,
            'career_readiness' => 4,
        ], RoadmapAction::PHASE_ORDER);
    }

    // ------------------------------------------------ B. invalid phase

    public function test_unknown_phase_is_rejected(): void
    {
        $this->assertPhaseRejected('basics', 1);
    }

    public function test_arbitrary_phase_string_is_rejected(): void
    {
        $this->assertPhaseRejected('totally_made_up', 1);
    }

    public function test_empty_phase_is_rejected(): void
    {
        $this->assertPhaseRejected('', 1);
    }

    // ------------------------------------------------ C. invalid order

    public function test_phase_order_zero_is_rejected(): void
    {
        $this->assertPhaseRejected('foundations', 0);
    }

    public function test_phase_order_five_is_rejected(): void
    {
        $this->assertPhaseRejected('foundations', 5);
    }

    public function test_phase_order_as_numeric_string_is_rejected(): void
    {
        $this->assertPhaseRejected('foundations', '1');
    }

    public function test_phase_order_as_float_is_rejected(): void
    {
        // NB: 1.0 would be JSON-encoded as `1` (an int) on the wire, so a
        // genuinely fractional value is used to prove the strict int check.
        $this->assertPhaseRejected('foundations', 1.5);
    }

    public function test_missing_phase_order_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 'foundations', 1);
        unset($roadmap['phases'][0]['order']);

        $this->assertInvalidRoadmap($roadmap, $profile, $role, $roleSkills);
    }

    // ------------------------------------------------ D. phase / order mismatch

    public function test_foundations_with_order_two_is_rejected(): void
    {
        $this->assertPhaseRejected('foundations', 2);
    }

    public function test_core_skills_with_order_one_is_rejected(): void
    {
        $this->assertPhaseRejected('core_skills', 1);
    }

    public function test_applied_practice_with_order_four_is_rejected(): void
    {
        $this->assertPhaseRejected('applied_practice', 4);
    }

    public function test_career_readiness_with_order_three_is_rejected(): void
    {
        $this->assertPhaseRejected('career_readiness', 3);
    }

    // ------------------------------------------------ E. mapPhase()

    public function test_map_phase_maps_every_known_phase_to_itself(): void
    {
        $service = app(IntelligencePersistenceService::class);

        foreach (array_keys(RoadmapAction::PHASE_ORDER) as $phase) {
            $this->assertSame($phase, $this->invokeMapPhase($service, $phase));
        }
    }

    public function test_map_phase_rejects_an_unknown_phase_instead_of_falling_back(): void
    {
        $service = app(IntelligencePersistenceService::class);

        try {
            $mapped = $this->invokeMapPhase($service, 'not_a_real_phase');
        } catch (IntelligenceException $e) {
            // Explicit failure with the project's standard mechanism...
            $this->assertSame('INTELLIGENCE_INVALID_RESPONSE', $e->codeName);
            $this->assertSame(502, $e->status);

            return;
        }

        // ...never a silent fallback to the old default.
        $this->fail('mapPhase() silently mapped an unknown phase to "'.$mapped.'".');
    }

    // ------------------------------------------------ helpers

    private function assertPhaseAccepted(string $phase, int $order): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $this->fakeCalculation(
            $profile,
            $role,
            $roleSkills,
            $this->roadmapResponse($profile, $role, $roleSkills, $phase, $order),
        );

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        // The persisted phase is exactly the one FastAPI returned.
        $this->assertSame($phase, RoadmapAction::query()->firstOrFail()->phase);
    }

    private function assertPhaseRejected(string $phase, mixed $order): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, $phase, $order);

        $this->assertInvalidRoadmap($roadmap, $profile, $role, $roleSkills);
    }

    /**
     * @param  array<string, mixed>  $roadmap
     */
    private function assertInvalidRoadmap(array $roadmap, $profile, $role, $roleSkills): void
    {
        $this->fakeCalculation($profile, $role, $roleSkills, $roadmap);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        // Nothing is persisted for an invalid phase — in particular the
        // unknown phase is never coerced into a stored "core_skills" action.
        $this->assertSame(0, Roadmap::count());
        $this->assertSame(0, RoadmapAction::count());
    }

    private function invokeMapPhase(IntelligencePersistenceService $service, string $phase): string
    {
        $method = new ReflectionMethod($service, 'mapPhase');
        $method->setAccessible(true);

        return $method->invoke($service, $phase);
    }

    private function fakeCalculation($profile, $role, $roleSkills, array $roadmap): void
    {
        Http::fake(function ($request) use ($profile, $role, $roleSkills, $roadmap) {
            if (str_contains($request->url(), '/roadmap')) {
                return Http::response($roadmap, 200);
            }

            return Http::response($this->skillGapResponse($profile, $role, $roleSkills), 200);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function roadmapResponse($profile, $role, $roleSkills, string $phase, mixed $order): array
    {
        return [
            'student_profile_id' => (int) $profile->id,
            'career_role_id' => (int) $role->id,
            'career_role_version' => (int) $role->version,
            'algorithm_version' => 'roadmap-v1',
            'configuration_version' => 'roadmap-config-v1',
            'roadmap_version' => 1,
            'status' => 'active',
            'estimated_total_hours' => 40.0,
            'estimated_duration_weeks' => 4,
            'limitations' => null,
            'phases' => [
                [
                    'phase' => $phase,
                    'order' => $order,
                    'actions' => [
                        $this->action('A1', (int) $roleSkills[0]->skill_id, (string) $roleSkills[0]->skill->name),
                    ],
                ],
            ],
            'next_best_action_id' => 'A1',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function action(string $id, int $targetSkillId, string $targetSkillName): array
    {
        return [
            'action_id' => $id,
            'action_type' => 'resource',
            'title' => 'Action '.$id,
            'objective' => 'Objective '.$id,
            'target_skill_id' => $targetSkillId,
            'target_skill_name' => $targetSkillName,
            'priority_score' => 0.5,
            'estimated_hours' => 12.0,
            'estimated_duration_weeks' => 3,
            'completion_criteria' => ['Completion criteria '.$id],
            'explanation' => ['Explanation '.$id],
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
            'weekly_availability_hours' => 20,
            'primary_career_role_id' => $role->id,
        ]);

        $roleSkills = collect();

        foreach ([
            ['SQL', 4.0, 0.60, true, 2.5],
            ['Python', 4.0, 0.40, false, 3.5],
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
