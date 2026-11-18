<?php

namespace Tests\Feature\Intelligence;

use App\Models\AlgorithmConfiguration;
use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\DecisionSnapshot;
use App\Models\ReadinessResult;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Readiness\CompositeReadinessInputs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompositeReadinessV2Test extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        config([
            'services.data_science.composite_readiness_enabled' => true,
            'services.data_science.roadmap_enabled' => false,
            'services.data_science.service_token' => 'test-service-token',
            'readiness.profile_completeness.required_fields' => ['university_name'],
        ]);
        AlgorithmConfiguration::create([
            'name' => 'intelligence', 'version' => 1, 'status' => 'active',
            'config' => [], 'activated_at' => now(),
        ]);
        $permission = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        $user = User::forceCreate(['name' => 'Learner', 'email' => 'v2@example.com',
            'password' => 'password123', 'status' => 'active', 'email_verified_at' => now()]);
        $user->roles()->attach($permission->id);
        $role = CareerRole::forceCreate(['title' => 'Data Analyst', 'slug' => 'data-analyst',
            'version' => 1, 'status' => 'approved']);
        $profile = StudentProfile::forceCreate(['user_id' => $user->id,
            'primary_career_role_id' => $role->id, 'university_name' => 'Test University']);
        $skill = Skill::create(['name' => 'SQL', 'slug' => 'sql']);
        CareerRoleSkill::create(['career_role_id' => $role->id, 'skill_id' => $skill->id,
            'required_level' => 4, 'importance_weight' => 1, 'is_critical' => false]);
        SkillEvaluation::forceCreate(['student_profile_id' => $profile->id, 'skill_id' => $skill->id,
            'level' => 3, 'confidence' => 100, 'algorithm_version' => 'baseline-v2.0',
            'calculated_at' => now(), 'snapshot' => ['source' => 'baseline_assessment']]);
        Sanctum::actingAs($user);

        return [$profile, $role, $skill];
    }

    private function fakeResult(array $request): array
    {
        return [
            'student_profile_id' => $request['student_profile_id'],
            'career_role_id' => $request['career_role_id'],
            'career_role_version' => 1, 'user_id' => $request['user_id'],
            'target_role' => 'Data Analyst', 'algorithm_version' => 'readiness-v2-srs13',
            'policy' => $request['policy'], 'base_readiness_score' => 75,
            'readiness_score' => 76.79, 'band' => 'near_ready',
            'practical_experience_component' => null, 'assessment_reliability_component' => null,
            'profile_completeness_component' => 100, 'is_provisional' => true,
            'components' => [], 'effective_weights' => ['skill_match' => 0.928571, 'profile_completeness' => 0.071429],
            'excluded_components' => ['practical_experience', 'assessment_reliability'],
            'critical_skill_cap_applied' => false, 'critical_skill_names' => [],
            'critical_skill_gap_count' => 0, 'total_skills' => 1, 'met_skills' => 0,
            'skills_with_gap' => 1, 'skill_results' => [array_merge($request['skills'][0],
                ['gap' => 1, 'status' => 'gap', 'match_score' => 75])],
        ];
    }

    public function test_v2_freezes_policy_excludes_answer_coverage_and_preserves_history(): void
    {
        [$profile, $role] = $this->scenario();
        $old = ReadinessResult::forceCreate(['student_profile_id' => $profile->id,
            'career_role_id' => $role->id, 'career_role_version' => 1,
            'score' => 54.89, 'skill_match_component' => 54.89, 'algorithm_version' => 'skill-gap-v1',
            'configuration_version' => 'config-v1', 'calculated_at' => now(), 'snapshot' => []]);
        Http::fake(function ($request) {
            $this->assertStringEndsWith('/api/v1/readiness', $request->url());
            $this->assertNull($request['assessment_reliability']);
            $this->assertNull($request['practical_experience']);
            $this->assertEquals(100, $request['profile_completeness']);

            return Http::response($this->fakeResult($request->data()));
        });
        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertCreated()->assertJsonPath('data.readiness.score', 76.79)
            ->assertJsonPath('data.readiness.is_provisional', true);
        $snapshot = DecisionSnapshot::latest('id')->firstOrFail();
        $this->assertSame('readiness-v2-srs13', $snapshot->snapshot['algorithm_version']);
        $this->assertEquals(0.65, $snapshot->snapshot['readiness_policy']['skill_match']);
        $this->assertNull($snapshot->snapshot['readiness_inputs']['assessment_reliability']);
        $this->assertEquals(54.89, $old->fresh()->score);
        $this->assertSame(2, ReadinessResult::count());
        $this->getJson('/api/v1/readiness/latest?career_role_id='.$role->id)->assertOk()
            ->assertJsonPath('data.algorithm_version', 'readiness-v2-srs13')
            ->assertJsonPath('data.excluded_components.1', 'assessment_reliability');
        $this->postJson('/api/v1/readiness/calculate', ['career_role_id' => $role->id])
            ->assertCreated()->assertJsonPath('data.score', 76.79)
            ->assertJsonPath('data.algorithm_version', 'readiness-v2-srs13');
    }

    public function test_mismatched_policy_never_persists_a_result(): void
    {
        [, $role] = $this->scenario();
        Http::fake(function ($request) {
            $result = $this->fakeResult($request->data());
            $result['policy']['critical_cap'] = 60;

            return Http::response($result);
        });
        $this->postJson('/api/v1/intelligence/calculate', ['career_role_id' => $role->id])
            ->assertStatus(502)->assertJsonPath('code', 'INTELLIGENCE_RESPONSE_MISMATCH');
        $this->assertSame(0, ReadinessResult::count());
        $this->assertSame('failed', DecisionSnapshot::firstOrFail()->status);
    }

    public function test_evidence_confidence_can_supply_reliability_without_using_baseline_coverage(): void
    {
        [$profile] = $this->scenario();
        $evaluation = SkillEvaluation::firstOrFail();
        $evaluation->forceFill(['algorithm_version' => 'skill-confidence-v1',
            'confidence' => 30, 'snapshot' => ['sources' => ['assessment_test' => ['weight' => .3]]]])->save();
        $inputs = app(CompositeReadinessInputs::class)->build($profile, collect([$evaluation]));
        $this->assertEquals(30, $inputs['assessment_reliability']);
    }
}
