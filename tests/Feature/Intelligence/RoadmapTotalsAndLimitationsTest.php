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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Roadmap v1 contract — the pieces added on top of the effort/duration
 * contract:
 *
 *  - `estimated_duration_weeks` is an INTEGER | null, and null is only
 *    legitimate when the learner has no weekly availability (null or 0);
 *  - the roadmap-level totals (`estimated_total_hours`,
 *    `estimated_duration_weeks`) are REQUIRED and persisted VERBATIM;
 *  - `limitations` is validated, persisted and exposed — never dropped;
 *  - every Roadmap action field is REQUIRED and validated by type.
 *
 * Every negative case must fail with 502 INTELLIGENCE_INVALID_RESPONSE and
 * persist NOTHING (no roadmap, no action).
 */
class RoadmapTotalsAndLimitationsTest extends TestCase
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

    // ------------------------------------------------ estimated_duration_weeks (action)

    public function test_action_duration_null_is_valid_without_weekly_availability(): void
    {
        // CASE A: weekly_availability_hours = null + estimated_duration_weeks = null.
        $this->assertActionDuration(null, null, 201);
    }

    public function test_action_duration_null_is_valid_with_zero_weekly_availability(): void
    {
        // CASE B: weekly_availability_hours = 0 + estimated_duration_weeks = null.
        $this->assertActionDuration(0, null, 201);
    }

    public function test_action_duration_integer_is_valid_without_weekly_availability(): void
    {
        // CASE C: null availability but a positive integer duration is fine.
        $this->assertActionDuration(null, 3, 201);
    }

    public function test_action_duration_null_is_rejected_with_weekly_availability(): void
    {
        // CASE D: availability > 0 but a null duration is a contract violation.
        $this->assertActionDuration(20, null, 502);
    }

    public function test_action_duration_zero_is_rejected_with_weekly_availability(): void
    {
        // CASE E.
        $this->assertActionDuration(20, 0, 502);
    }

    public function test_action_duration_negative_is_rejected(): void
    {
        // CASE F.
        $this->assertActionDuration(20, -1, 502);
    }

    public function test_action_duration_float_is_rejected(): void
    {
        // CASE G: the contract is integer | null — 1.5 is never rounded.
        $this->assertActionDuration(20, 1.5, 502);
    }

    public function test_action_duration_numeric_string_is_rejected(): void
    {
        // CASE H: "3" is NOT coerced to 3.
        $this->assertActionDuration(20, '3', 502);
    }

    // ------------------------------------------------ roadmap-level duration

    public function test_roadmap_duration_null_is_valid_without_weekly_availability(): void
    {
        $this->assertRoadmapDuration(null, null, 201);
    }

    public function test_roadmap_duration_null_is_valid_with_zero_weekly_availability(): void
    {
        $this->assertRoadmapDuration(0, null, 201);
    }

    public function test_roadmap_duration_null_is_rejected_with_weekly_availability(): void
    {
        $this->assertRoadmapDuration(20, null, 502);
    }

    // ------------------------------------------------ action duration serialization

    public function test_resource_serializes_action_duration_weeks_as_integer(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, 4, null);
        $roadmap['phases'][0]['actions'][0]['estimated_duration_weeks'] = 4;
        $this->fakeCalculation($profile, $role, $roleSkills, $roadmap);

        $response = $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $weeks = $response->json('data.roadmap.actions.0.estimated_duration_weeks');

        // integer | null — a whole number of weeks, never a float.
        $this->assertIsInt($weeks);
        $this->assertSame(4, $weeks);
    }

    public function test_resource_serializes_null_action_duration_weeks_as_null(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario(null);
        Sanctum::actingAs($user);

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, null, null);
        $roadmap['phases'][0]['actions'][0]['estimated_duration_weeks'] = null;
        $this->fakeCalculation($profile, $role, $roleSkills, $roadmap);

        $response = $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        $weeks = $response->json('data.roadmap.actions.0.estimated_duration_weeks');

        // null must stay null — never coerced to 0.
        $this->assertNull($weeks);
        $this->assertNotSame(0, $weeks);
    }

    public function test_roadmap_actions_duration_weeks_column_is_an_integer_type(): void
    {
        // The column must be INTEGER NULL, not DECIMAL (Roadmap v1).
        $type = Schema::getColumnType('roadmap_actions', 'estimated_duration_weeks');

        $this->assertStringContainsString('int', strtolower($type));
    }

    // ------------------------------------------------ roadmap totals

    public function test_roadmap_totals_are_persisted_and_exposed(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        // FastAPI reports 40 total hours / 4 weeks; the single action only
        // accounts for 12 hours, proving the total is NOT re-derived by
        // summing the actions.
        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, 4, null);
        $this->fakeCalculation($profile, $role, $roleSkills, $roadmap);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->assertJsonPath('data.roadmap.estimated_total_hours', fn ($v) => abs((float) $v - 40.0) < 0.0001)
            ->assertJsonPath('data.roadmap.estimated_duration_weeks', 4);

        $model = Roadmap::query()->firstOrFail();
        $this->assertEqualsWithDelta(40.0, (float) $model->estimated_total_hours, 0.0001);
        $this->assertSame(4, (int) $model->estimated_duration_weeks);
    }

    public function test_missing_roadmap_total_hours_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, 4, null);
        unset($roadmap['estimated_total_hours']);

        $this->assertInvalidRoadmap($roadmap, $profile, $role, $roleSkills);
    }

    public function test_missing_roadmap_duration_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, 4, null);
        unset($roadmap['estimated_duration_weeks']);

        $this->assertInvalidRoadmap($roadmap, $profile, $role, $roleSkills);
    }

    // ------------------------------------------------ limitations

    public function test_limitations_are_persisted_and_exposed(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        $limitations = [
            'market_demand_factor_unavailable_neutral_1_0',
            'weekly_availability_limited',
        ];

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, 4, $limitations);
        $this->fakeCalculation($profile, $role, $roleSkills, $roadmap);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->assertJsonPath('data.roadmap.limitations', $limitations);

        $model = Roadmap::query()->firstOrFail();
        $this->assertSame($limitations, $model->limitations);
    }

    public function test_limitations_and_totals_are_returned_by_the_latest_endpoint(): void
    {
        // End-to-end: FastAPI fake response -> validator -> persistence ->
        // read model -> resource, without relying on the calculate response.
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        $limitations = ['market_demand_factor_unavailable_neutral_1_0'];
        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, 4, $limitations);
        $this->fakeCalculation($profile, $role, $roleSkills, $roadmap);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])->assertStatus(201);

        $this->getJson('/api/v1/intelligence/latest?career_role_id='.$role->id)
            ->assertOk()
            ->assertJsonPath('data.roadmap.limitations', $limitations)
            ->assertJsonPath('data.roadmap.estimated_duration_weeks', 4)
            ->assertJsonPath('data.roadmap.estimated_total_hours', fn ($v) => abs((float) $v - 40.0) < 0.0001);
    }

    public function test_non_list_limitations_are_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        // An object where a list of codes is expected is a contract violation.
        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, 4, ['reason' => 'x']);
        $this->assertInvalidRoadmap($roadmap, $profile, $role, $roleSkills);
    }

    public function test_non_string_limitation_entry_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, 4, [123]);
        $this->assertInvalidRoadmap($roadmap, $profile, $role, $roleSkills);
    }

    // ------------------------------------------------ required action fields

    public function test_missing_objective_is_rejected(): void
    {
        $this->assertMissingActionField('objective');
    }

    public function test_missing_target_skill_id_is_rejected(): void
    {
        $this->assertMissingActionField('target_skill_id');
    }

    public function test_missing_target_skill_name_is_rejected(): void
    {
        $this->assertMissingActionField('target_skill_name');
    }

    public function test_missing_priority_score_is_rejected(): void
    {
        $this->assertMissingActionField('priority_score');
    }

    public function test_missing_estimated_hours_is_rejected(): void
    {
        $this->assertMissingActionField('estimated_hours');
    }

    public function test_missing_estimated_duration_weeks_is_rejected(): void
    {
        $this->assertMissingActionField('estimated_duration_weeks');
    }

    public function test_missing_completion_criteria_is_rejected(): void
    {
        $this->assertMissingActionField('completion_criteria');
    }

    public function test_missing_explanation_is_rejected(): void
    {
        $this->assertMissingActionField('explanation');
    }

    public function test_unknown_target_skill_id_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, 4, null);
        $roadmap['phases'][0]['actions'][0]['target_skill_id'] = 999999;

        $this->assertInvalidRoadmap($roadmap, $profile, $role, $roleSkills);
    }

    public function test_priority_score_above_one_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, 4, null);
        $roadmap['phases'][0]['actions'][0]['priority_score'] = 1.5;

        $this->assertInvalidRoadmap($roadmap, $profile, $role, $roleSkills);
    }

    // ------------------------------------------------ completion_criteria / explanation (string[])

    public function test_action_completion_criteria_and_explanation_accept_string_lists(): void
    {
        // A + B: both fields are lists of non-empty strings.
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        $criteria = ['Complete task', 'Pass validation'];
        $explanation = ['Critical skill', 'Largest gap'];

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, 4, null);
        $roadmap['phases'][0]['actions'][0]['completion_criteria'] = $criteria;
        $roadmap['phases'][0]['actions'][0]['explanation'] = $explanation;

        $this->fakeCalculation($profile, $role, $roleSkills, $roadmap);

        $response = $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        // The API returns ARRAYS, never an imploded string.
        $this->assertIsArray($response->json('data.roadmap.actions.0.completion_criteria'));
        $this->assertIsArray($response->json('data.roadmap.actions.0.explanation'));
        $this->assertSame($criteria, $response->json('data.roadmap.actions.0.completion_criteria'));
        $this->assertSame($explanation, $response->json('data.roadmap.actions.0.explanation'));

        $action = RoadmapAction::query()->firstOrFail();
        $this->assertSame($criteria, $action->completion_criteria);
        $this->assertSame($explanation, $action->explanation);
    }

    public function test_action_completion_criteria_as_string_is_rejected(): void
    {
        // C: a plain string is not accepted.
        $this->assertActionFieldRejected('completion_criteria', 'Complete task');
    }

    public function test_action_explanation_as_string_is_rejected(): void
    {
        // D.
        $this->assertActionFieldRejected('explanation', 'Explanation');
    }

    public function test_action_completion_criteria_empty_list_is_rejected(): void
    {
        // E.
        $this->assertActionFieldRejected('completion_criteria', []);
    }

    public function test_action_explanation_empty_list_is_rejected(): void
    {
        // F.
        $this->assertActionFieldRejected('explanation', []);
    }

    public function test_action_completion_criteria_blank_element_is_rejected(): void
    {
        // G.
        $this->assertActionFieldRejected('completion_criteria', ['valid', '']);
    }

    public function test_action_explanation_blank_element_is_rejected(): void
    {
        // H.
        $this->assertActionFieldRejected('explanation', ['valid', '']);
    }

    public function test_action_completion_criteria_non_string_element_is_rejected(): void
    {
        $this->assertActionFieldRejected('completion_criteria', ['valid', 123]);
    }

    public function test_action_explanation_blank_single_element_is_rejected(): void
    {
        $this->assertActionFieldRejected('explanation', ['   ']);
    }

    // ------------------------------------------------ roadmap zero duration

    public function test_zero_effort_roadmap_with_zero_duration_is_valid(): void
    {
        // I: total = 0 and duration = 0 is a valid roadmap.
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 0, 0, null);
        $this->fakeCalculation($profile, $role, $roleSkills, $roadmap);

        $response = $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);

        // 0 stays integer 0 in the API output.
        $duration = $response->json('data.roadmap.estimated_duration_weeks');
        $this->assertIsInt($duration);
        $this->assertSame(0, $duration);

        $model = Roadmap::query()->firstOrFail();
        $this->assertEqualsWithDelta(0.0, (float) $model->estimated_total_hours, 0.0001);
        $this->assertSame(0, (int) $model->estimated_duration_weeks);
    }

    public function test_roadmap_zero_duration_is_rejected_when_effort_and_availability_exist(): void
    {
        // J: effort > 0 AND weekly availability > 0 => duration must be > 0.
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, 0, null);
        $this->assertInvalidRoadmap($roadmap, $profile, $role, $roleSkills);
    }

    public function test_zero_effort_roadmap_zero_duration_is_valid_without_availability(): void
    {
        // The cross-field rule needs availability too: total = 0 => 0 is fine.
        [$user, $profile, $role, $roleSkills] = $this->createScenario(null);
        Sanctum::actingAs($user);

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 0, 0, null);
        $this->fakeCalculation($profile, $role, $roleSkills, $roadmap);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);
    }

    // ------------------------------------------------ blocking_prerequisite_skill_ids

    public function test_blocking_prerequisite_skill_ids_survive_validation_persistence_and_resource(): void
    {
        // M.
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        $blocking = [(int) $roleSkills[0]->skill_id, (int) $roleSkills[1]->skill_id];

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, 4, null);
        $roadmap['phases'][0]['actions'][0]['blocking_prerequisite_skill_ids'] = $blocking;

        $this->fakeCalculation($profile, $role, $roleSkills, $roadmap);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->assertJsonPath('data.roadmap.actions.0.blocking_prerequisite_skill_ids', $blocking);

        $action = RoadmapAction::query()->firstOrFail();
        $this->assertSame($blocking, $action->blocking_prerequisite_skill_ids);

        // It must NOT leak into the declared prerequisite relation.
        $this->assertSame([], $action->prerequisites->pluck('id')->all());
    }

    public function test_unknown_blocking_prerequisite_skill_id_is_rejected(): void
    {
        // N.
        $this->assertActionFieldRejected('blocking_prerequisite_skill_ids', [999999]);
    }

    public function test_duplicate_blocking_prerequisite_skill_ids_are_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        $duplicate = (int) $roleSkills[0]->skill_id;
        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, 4, null);
        $roadmap['phases'][0]['actions'][0]['blocking_prerequisite_skill_ids'] = [$duplicate, $duplicate];

        $this->assertInvalidRoadmap($roadmap, $profile, $role, $roleSkills);
    }

    public function test_blocking_prerequisite_skill_ids_as_string_is_rejected(): void
    {
        $this->assertActionFieldRejected('blocking_prerequisite_skill_ids', '1,2');
    }

    public function test_blocking_prerequisite_skill_ids_with_float_id_is_rejected(): void
    {
        $this->assertActionFieldRejected('blocking_prerequisite_skill_ids', [1.5]);
    }

    public function test_empty_blocking_prerequisite_skill_ids_is_accepted(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, 4, null);
        $roadmap['phases'][0]['actions'][0]['blocking_prerequisite_skill_ids'] = [];

        $this->fakeCalculation($profile, $role, $roleSkills, $roadmap);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201)
            ->assertJsonPath('data.roadmap.actions.0.blocking_prerequisite_skill_ids', []);
    }

    // ------------------------------------------------ target_skill_name consistency

    public function test_target_skill_name_matching_the_payload_skill_is_accepted(): void
    {
        // O: the fixture uses the canonical payload skill name.
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        $canonicalName = (string) $roleSkills[0]->skill->name;

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, 4, null);
        $roadmap['phases'][0]['actions'][0]['target_skill_name'] = $canonicalName;

        $this->fakeCalculation($profile, $role, $roleSkills, $roadmap);

        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(201);
    }

    public function test_target_skill_name_mismatch_is_rejected(): void
    {
        // P: the id and the name must refer to the SAME skill.
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, 4, null);
        $roadmap['phases'][0]['actions'][0]['target_skill_name'] = 'Python';

        $this->assertInvalidRoadmap($roadmap, $profile, $role, $roleSkills);
    }

    public function test_target_skill_name_with_different_casing_is_rejected(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, 4, null);
        $roadmap['phases'][0]['actions'][0]['target_skill_name'] = strtolower((string) $roleSkills[0]->skill->name);

        $this->assertInvalidRoadmap($roadmap, $profile, $role, $roleSkills);
    }

    // ------------------------------------------------ helpers

    private function assertActionFieldRejected(string $field, mixed $value): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, 4, null);
        $roadmap['phases'][0]['actions'][0][$field] = $value;

        $this->assertInvalidRoadmap($roadmap, $profile, $role, $roleSkills);
    }

    private function assertActionDuration(?float $weeklyHours, mixed $weeks, int $expectedStatus): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario($weeklyHours);
        Sanctum::actingAs($user);

        $roadmapWeeks = $weeklyHours !== null && $weeklyHours > 0 ? 4 : null;
        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, $roadmapWeeks, null);
        $roadmap['phases'][0]['actions'][0]['estimated_duration_weeks'] = $weeks;

        $this->fakeCalculation($profile, $role, $roleSkills, $roadmap);

        $response = $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id]);
        $response->assertStatus($expectedStatus);

        if ($expectedStatus !== 201) {
            $response->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');
            $this->assertSame(0, Roadmap::count());
            $this->assertSame(0, RoadmapAction::count());
        }
    }

    private function assertRoadmapDuration(?float $weeklyHours, mixed $roadmapWeeks, int $expectedStatus): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario($weeklyHours);
        Sanctum::actingAs($user);

        // Keep the ACTION duration valid for the availability so the roadmap
        // level is the only thing under test.
        $actionWeeks = $weeklyHours !== null && $weeklyHours > 0 ? 3 : null;

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, $roadmapWeeks, null);
        $roadmap['phases'][0]['actions'][0]['estimated_duration_weeks'] = $actionWeeks;

        $this->fakeCalculation($profile, $role, $roleSkills, $roadmap);

        $response = $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id]);
        $response->assertStatus($expectedStatus);

        if ($expectedStatus !== 201) {
            $response->assertJsonPath('code', 'INTELLIGENCE_INVALID_RESPONSE');
            $this->assertSame(0, Roadmap::count());
            $this->assertSame(0, RoadmapAction::count());
        }
    }

    private function assertMissingActionField(string $field): void
    {
        [$user, $profile, $role, $roleSkills] = $this->createScenario(20);
        Sanctum::actingAs($user);

        $roadmap = $this->roadmapResponse($profile, $role, $roleSkills, 40.0, 4, null);
        unset($roadmap['phases'][0]['actions'][0][$field]);

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

        // A rejected response must persist NOTHING.
        $this->assertSame(0, Roadmap::count());
        $this->assertSame(0, RoadmapAction::count());
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
    private function roadmapResponse($profile, $role, $roleSkills, mixed $totalHours, mixed $durationWeeks, ?array $limitations): array
    {
        return [
            'student_profile_id' => (int) $profile->id,
            'career_role_id' => (int) $role->id,
            'career_role_version' => (int) $role->version,
            'algorithm_version' => 'roadmap-v1',
            'configuration_version' => 'roadmap-config-v1',
            'roadmap_version' => 1,
            'status' => 'active',
            'estimated_total_hours' => $totalHours,
            'estimated_duration_weeks' => $durationWeeks,
            'limitations' => $limitations,
            'phases' => [
                [
                    'phase' => 'foundations',
                    'actions' => [
                        $this->action('A1', 'resource', (int) $roleSkills[0]->skill_id, (string) $roleSkills[0]->skill->name),
                    ],
                ],
            ],
            'next_best_action_id' => 'A1',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function action(string $id, string $type, int $targetSkillId, string $targetSkillName): array
    {
        return [
            'action_id' => $id,
            'action_type' => $type,
            'title' => 'Action '.$id,
            'objective' => 'Objective '.$id,
            'target_skill_id' => $targetSkillId,
            'target_skill_name' => $targetSkillName,
            'priority_score' => 0.5,
            'estimated_hours' => 12.0,
            'estimated_duration_weeks' => 3,
            // Roadmap v1: lists of strings, never a plain string.
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
    private function createScenario(?float $weeklyAvailabilityHours = null): array
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
            'weekly_availability_hours' => $weeklyAvailabilityHours,
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
