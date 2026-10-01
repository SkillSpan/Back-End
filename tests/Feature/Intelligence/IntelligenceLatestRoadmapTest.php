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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * US-INT-01 §22/§28 — the roadmap half of the intelligence decision and
 * the `GET /api/v1/intelligence/latest` read model.
 *
 * The Roadmap v1 endpoint is published at POST /api/v1/roadmap, but
 * generation is feature-flagged off (DATA_SCIENCE_ROADMAP_ENABLED=false)
 * so enabling it is an explicit decision. These tests pin the two things
 * that matter: (1) a disabled flag must never fabricate a roadmap, and
 * must not disturb skill gaps / readiness; (2) when a roadmap IS
 * persisted, the latest endpoint must actually return it.
 */
class IntelligenceLatestRoadmapTest extends TestCase
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

    // ------------------------------------------------ feature flag

    public function test_roadmap_is_not_generated_while_the_flag_is_disabled(): void
    {
        // Default configuration: DATA_SCIENCE_ROADMAP_ENABLED is false, so
        // roadmap generation is opt-in and never runs implicitly.
        $this->assertFalse((bool) config('services.data_science.roadmap_enabled'));

        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $urls = [];
        Http::fake(function ($request) use (&$urls, $profile, $role, $roleSkills) {
            $urls[] = $request->url();

            return Http::response($this->skillGapResponse($profile, $role, $roleSkills), 200);
        });

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201)
            // A disabled roadmap is reported as "no roadmap", never as an
            // empty or fabricated one.
            ->assertJsonPath('data.roadmap', null);

        // No roadmap endpoint may be called while the flag is off.
        $this->assertSame(
            [],
            array_values(array_filter($urls, fn (string $url) => str_contains($url, 'roadmap'))),
            'the roadmap endpoint must not be called while generation is disabled',
        );

        $this->assertSame(0, Roadmap::count());
        $this->assertSame(0, RoadmapAction::count());

        // The disabled flag must not disturb the rest of the decision.
        $snapshot = DecisionSnapshot::where('student_profile_id', $profile->id)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame(DecisionSnapshot::STATUS_SUCCEEDED, $snapshot->status);
        $this->assertSame(1, ReadinessResult::where('decision_snapshot_id', $snapshot->id)->count());
        $this->assertSame(3, SkillGapResult::where('decision_snapshot_id', $snapshot->id)->count());
    }

    public function test_latest_reports_no_roadmap_when_none_was_generated(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);
        $this->fakeCalculation($profile, $role, $roleSkills, withRoadmap: false);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $this->getJson('/api/v1/intelligence/latest?career_role_id='.$role->id)
            ->assertOk()
            ->assertJsonPath('data.roadmap', null);
    }

    // ------------------------------------------------ roadmap persistence

    public function test_generated_roadmap_is_returned_by_the_latest_endpoint(): void
    {
        config(['services.data_science.roadmap_enabled' => true]);

        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);
        $this->fakeCalculation($profile, $role, $roleSkills, withRoadmap: true);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->assertJsonPath('data.roadmap.roadmap_version', 1)
            ->assertJsonPath('data.roadmap.status', Roadmap::STATUS_ACTIVE);

        $snapshot = DecisionSnapshot::where('student_profile_id', $profile->id)->first();
        $roadmap = Roadmap::where('student_profile_id', $profile->id)->first();

        $this->assertNotNull($roadmap);
        $this->assertSame($snapshot->id, $roadmap->decision_snapshot_id);
        $this->assertSame((int) $role->id, $roadmap->career_role_id);
        $this->assertSame((int) $role->version, $roadmap->career_role_version);

        $response = $this->getJson('/api/v1/intelligence/latest?career_role_id='.$role->id)->assertOk();

        // The roadmap linked to the latest successful decision must be
        // returned — with its actions, not just its header row.
        $this->assertNotNull($response->json('data.roadmap'), 'the stored roadmap must be exposed by /intelligence/latest');
        $this->assertSame($roadmap->id, $response->json('data.roadmap.id'));
        $this->assertSame(2, count($response->json('data.roadmap.actions')));
        $this->assertSame('Brush up SQL fundamentals', $response->json('data.roadmap.actions.0.title'));
        $this->assertSame('foundations', $response->json('data.roadmap.actions.0.phase'));
    }

    public function test_latest_returns_the_roadmap_of_the_latest_intelligence_decision(): void
    {
        config(['services.data_science.roadmap_enabled' => true]);

        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $this->fakeCalculation($profile, $role, $roleSkills, withRoadmap: true);
        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])->assertStatus(201);

        $this->fakeCalculation($profile, $role, $roleSkills, withRoadmap: true);
        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])->assertStatus(201);

        $roadmaps = Roadmap::orderBy('version')->get();
        $this->assertCount(2, $roadmaps);
        $this->assertSame(Roadmap::STATUS_SUPERSEDED, $roadmaps[0]->status);
        $this->assertSame(Roadmap::STATUS_ACTIVE, $roadmaps[1]->status);

        $response = $this->getJson('/api/v1/intelligence/latest?career_role_id='.$role->id)->assertOk();

        $this->assertSame($roadmaps[1]->id, $response->json('data.roadmap.id'));
        $this->assertSame(2, $response->json('data.roadmap.roadmap_version'));
    }

    /**
     * The legacy composite readiness flow (`POST /readiness/calculate`)
     * writes its own SUCCEEDED decision snapshot, which carries readiness
     * and skill-gap rows but never a roadmap. Because
     * `GET /intelligence/latest` used to select the newest succeeded
     * snapshot regardless of which flow produced it, a readiness
     * calculation silently hid the intelligence roadmap that exists in the
     * database — the roadmap was there, the endpoint reported null.
     */
    public function test_a_readiness_calculation_does_not_hide_the_intelligence_roadmap(): void
    {
        config(['services.data_science.roadmap_enabled' => true]);

        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        Http::fake(function ($request) use ($profile, $role, $roleSkills) {
            if (str_contains($request->url(), '/skill-gap')) {
                return Http::response($this->skillGapResponse($profile, $role, $roleSkills), 200);
            }

            if (str_contains($request->url(), '/roadmap')) {
                return Http::response($this->roadmapResponse($profile, $role, $roleSkills), 200);
            }

            if (str_contains($request->url(), '/skill-match')) {
                return Http::response($this->skillMatchResponse($profile, $role, $roleSkills), 200);
            }

            return Http::response(['detail' => 'unexpected endpoint'], 404);
        });

        // 1. An intelligence decision WITH a roadmap.
        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $intelligenceSnapshot = DecisionSnapshot::orderBy('id')->first();
        $roadmap = Roadmap::where('decision_snapshot_id', $intelligenceSnapshot->id)->first();
        $this->assertNotNull($roadmap);

        // 2. A legacy readiness calculation for the same learner + role.
        $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $this->assertSame(2, DecisionSnapshot::where('status', DecisionSnapshot::STATUS_SUCCEEDED)->count());

        // 3. The roadmap must still be reachable.
        $response = $this->getJson('/api/v1/intelligence/latest?career_role_id='.$role->id)->assertOk();

        $this->assertNotNull(
            $response->json('data.roadmap'),
            'a readiness snapshot must not shadow the latest intelligence roadmap',
        );
        $this->assertSame($roadmap->id, $response->json('data.roadmap.id'));
    }

    /**
     * The discriminator is written for BOTH flows at snapshot creation, not
     * inferred at read time.
     *
     * `GET /intelligence/latest` selects on the `flow` column, and a NULL
     * value is treated as intelligence (that is what every pre-column row
     * was). A flow that failed to tag itself would therefore be misread as
     * an intelligence decision — so the write side is pinned here directly,
     * not only through the endpoint's behaviour.
     */
    public function test_each_flow_tags_its_own_decision_snapshot(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        Http::fake(function ($request) use ($profile, $role, $roleSkills) {
            if (str_contains($request->url(), '/skill-match')) {
                return Http::response($this->skillMatchResponse($profile, $role, $roleSkills), 200);
            }

            return Http::response($this->skillGapResponse($profile, $role, $roleSkills), 200);
        });

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);
        $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $this->assertSame(
            [DecisionSnapshot::FLOW_INTELLIGENCE, DecisionSnapshot::FLOW_READINESS_LEGACY],
            DecisionSnapshot::orderBy('id')->pluck('flow')->all(),
        );
    }

    // ------------------------------------------------ failure handling

    public function test_invalid_roadmap_response_does_not_persist_a_partial_decision(): void
    {
        config(['services.data_science.roadmap_enabled' => true]);

        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $invalidRoadmap = $this->roadmapResponse($profile, $role, $roleSkills);
        unset($invalidRoadmap['phases']);

        Http::fake(function ($request) use ($profile, $role, $roleSkills, $invalidRoadmap) {
            if (str_contains($request->url(), '/roadmap')) {
                return Http::response($invalidRoadmap, 200);
            }

            return Http::response($this->skillGapResponse($profile, $role, $roleSkills), 200);
        });

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');

        // A decision is atomic: a failed roadmap must not leave a
        // half-written "successful" result behind.
        $snapshot = DecisionSnapshot::where('student_profile_id', $profile->id)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame(DecisionSnapshot::STATUS_FAILED, $snapshot->status);
        $this->assertNull($snapshot->calculated_at);

        $this->assertSame(0, ReadinessResult::where('student_profile_id', $profile->id)->count());
        $this->assertSame(0, SkillGapResult::count());
        $this->assertSame(0, Roadmap::count());
        $this->assertSame(0, RoadmapAction::count());
    }

    public function test_roadmap_service_failure_does_not_persist_a_partial_decision(): void
    {
        config(['services.data_science.roadmap_enabled' => true]);

        [$user, $profile, $role, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        Http::fake(function ($request) use ($profile, $role, $roleSkills) {
            if (str_contains($request->url(), '/roadmap')) {
                return Http::response(['message' => 'boom'], 500);
            }

            return Http::response($this->skillGapResponse($profile, $role, $roleSkills), 200);
        });

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(503)
            ->assertJsonPath('code', 'INTELLIGENCE_UNAVAILABLE');

        $this->assertSame(0, ReadinessResult::where('student_profile_id', $profile->id)->count());
        $this->assertSame(0, SkillGapResult::count());
        $this->assertSame(0, Roadmap::count());
    }

    // ------------------------------------------------ helpers

    private function fakeCalculation($profile, $role, $roleSkills, bool $withRoadmap): void
    {
        Http::fake(function ($request) use ($profile, $role, $roleSkills, $withRoadmap) {
            if (str_contains($request->url(), '/roadmap')) {
                return $withRoadmap
                    ? Http::response($this->roadmapResponse($profile, $role, $roleSkills), 200)
                    : Http::response(['detail' => 'roadmap disabled'], 404);
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
            'algorithm_version' => 'intelligence-v1',
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
     * The legacy readiness flow's Skill Match v1 contract, derived from the
     * fixture so the validator's arithmetic checks hold.
     */
    private function skillMatchResponse($profile, $role, $roleSkills): array
    {
        $weightTotal = (float) $roleSkills->sum('importance_weight');
        $skillResults = [];
        $metSkills = 0;
        $partialSkills = 0;
        $weightedAchievedTotal = 0.0;
        $weightedRequiredTotal = 0.0;

        foreach ($roleSkills as $roleSkill) {
            $currentLevel = (float) SkillEvaluation::where('student_profile_id', $profile->id)
                ->where('skill_id', $roleSkill->skill_id)
                ->orderByDesc('id')
                ->first()
                ->level;

            $requiredLevel = (float) $roleSkill->required_level;
            $achievedLevel = min($currentLevel, $requiredLevel);
            $matchRatio = min(max($achievedLevel / $requiredLevel, 0.0), 1.0);
            $status = $matchRatio >= 1.0 ? 'met' : 'partial';

            $status === 'met' ? $metSkills++ : $partialSkills++;

            $normalizedWeight = $weightTotal > 0 ? (float) $roleSkill->importance_weight / $weightTotal : 0.0;
            $weightedAchievedTotal += $normalizedWeight * $achievedLevel;
            $weightedRequiredTotal += $normalizedWeight * $requiredLevel;

            $skillResults[] = [
                'skill_id' => (int) $roleSkill->skill_id,
                'skill_name' => (string) $roleSkill->skill->name,
                'current_level' => $currentLevel,
                'required_level' => $requiredLevel,
                'importance_weight' => (float) $roleSkill->importance_weight,
                'normalized_importance_weight' => $normalizedWeight,
                'achieved_level' => $achievedLevel,
                'match_ratio' => $matchRatio,
                'is_critical' => (bool) $roleSkill->is_critical,
                'status' => $status,
            ];
        }

        return [
            'student_profile_id' => (int) $profile->id,
            'career_role_id' => (int) $role->id,
            'career_role_version' => (int) $role->version,
            'user_id' => (int) $profile->user_id,
            'target_role' => (string) $role->title,
            'algorithm_version' => 'skill-match-v1',
            'weight_configuration_version' => 'weights-v1',
            'original_weight_total' => $weightTotal,
            'normalized_weight_total' => 1.0,
            'weighted_achieved_total' => $weightedAchievedTotal,
            'weighted_required_total' => $weightedRequiredTotal,
            'skill_match_score' => round($weightedAchievedTotal / $weightedRequiredTotal * 100, 2),
            'total_skills' => count($skillResults),
            'met_skills' => $metSkills,
            'partial_skills' => $partialSkills,
            'not_required_skills' => 0,
            'skill_results' => $skillResults,
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
            // Roadmap v1 totals + limitations. This learner has NO weekly
            // availability, so a null calendar duration is valid.
            'estimated_total_hours' => 10.0,
            'estimated_duration_weeks' => null,
            'limitations' => [],
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
                            'title' => 'Practice joins and aggregation',
                            'objective' => 'Practise joins and aggregation',
                            'target_skill_id' => (int) $roleSkills[1]->skill_id,
                            'target_skill_name' => (string) $roleSkills[1]->skill->name,
                            'prerequisite_skill_ids' => [(int) $roleSkills[0]->skill_id],
                            'priority_score' => 0.6,
                            'estimated_hours' => 4.0,
                            'estimated_duration_weeks' => 1,
                            'completion_criteria' => 'Complete the aggregation exercises',
                            'explanation' => 'Critical skill reinforcing the foundation',
                        ],
                    ],
                ],
            ],
            'next_best_action_id' => 'A1',
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
            'availability' => 'full_time',
            'primary_career_role_id' => $role->id,
        ]);

        $roleSkills = collect();

        foreach ([
            ['SQL', 4.0, 0.45, true, 2.5],
            ['Python', 4.0, 0.30, true, 3.5],
            ['Power BI', 4.0, 0.25, false, 4.0],
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
