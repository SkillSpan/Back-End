<?php

namespace Tests\Feature\Intelligence;

use App\Events\SkillDataChanged;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The reported symptom, end to end: "readiness/latest and intelligence/latest
 * return 404 even after the assessment".
 *
 * Both endpoints are pure reads — `readiness_results` and a SUCCEEDED
 * `decision_snapshots` row — and nothing in the assessment flow wrote either
 * one directly. The chain was:
 *
 *   assessment submit → SkillDataChanged → RecalculateIntelligence (queued)
 *     → IntelligenceService::calculate($profile, null, …)
 *     → $careerRoleId ??= $profile->primary_career_role_id
 *     → null → CAREER_ROLE_REQUIRED → caught, logged, swallowed
 *
 * `primary_career_role_id` was only ever written by `PUT /profile`, so a
 * learner who assessed and never opened the profile form left the whole chain
 * a no-op — silently, which is why it looked like a broken endpoint.
 *
 * These tests pin the two halves: with a role the chain produces both rows, and
 * without one it stays a quiet no-op instead of failing the request that
 * triggered it.
 */
class ReadinessAfterSkillChangeTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);

        config([
            'services.data_science.service_token' => 'test-service-token',
            'services.data_science.roadmap.enabled' => false,
        ]);

        AlgorithmConfiguration::create([
            'name' => 'intelligence',
            'version' => 1,
            'status' => 'active',
            'config' => ['skill_scale' => [0, 5]],
            'activated_at' => now(),
        ]);
    }

    /**
     * @return array{0: User, 1: StudentProfile, 2: CareerRole, 3: array<int, CareerRoleSkill>}
     */
    private function scenario(?int $primaryRoleId = null): array
    {
        $user = User::forceCreate([
            'name' => 'Test Learner',
            'email' => 'learner@example.com',
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
            'effective_date' => now()->toDateString(),
        ]);

        $profile = StudentProfile::forceCreate([
            'user_id' => $user->id,
            'availability' => 'full_time',
            'primary_career_role_id' => $primaryRoleId,
        ]);

        $roleSkills = [];

        foreach ([['SQL', 4.0, 0.6, true, 3.0], ['Python', 3.0, 0.4, false, 2.0]] as [$name, $required, $weight, $critical, $current]) {
            $skill = Skill::create(['name' => $name, 'slug' => strtolower($name).'-'.uniqid()]);

            $roleSkills[] = CareerRoleSkill::create([
                'career_role_id' => $role->id,
                'skill_id' => $skill->id,
                'required_level' => $required,
                'importance_weight' => $weight,
                'is_critical' => $critical,
            ]);

            // The intelligence flow refuses to run when a required role skill
            // has no evaluation (ASSESSMENT_INCOMPLETE) — deliberately, no
            // fallback to the projection.
            SkillEvaluation::forceCreate([
                'student_profile_id' => $profile->id,
                'skill_id' => $skill->id,
                'level' => $current,
                'confidence' => 90,
                'algorithm_version' => 'baseline-v1',
                'calculated_at' => now(),
            ]);
        }

        return [$user, $profile, $role, $roleSkills];
    }

    private function fakeSkillGap(StudentProfile $profile, CareerRole $role, array $roleSkills): void
    {
        $results = [];

        foreach ($roleSkills as $roleSkill) {
            $current = (float) SkillEvaluation::where('student_profile_id', $profile->id)
                ->where('skill_id', $roleSkill->skill_id)
                ->value('level');

            $required = (float) $roleSkill->required_level;
            $gap = max($required - $current, 0.0);

            $results[] = [
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

        $met = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'met'));

        Http::fake([
            '*/api/v1/skill-gap' => Http::response(array_merge([
                'student_profile_id' => (int) $profile->id,
                'career_role_id' => (int) $role->id,
                'career_role_version' => (int) $role->version,
                'algorithm_version' => 'intelligence-v1',
                'skill_results' => $results,
            ], [
                'base_readiness_score' => 72.5,
                'readiness_score' => 72.5,
                'critical_skill_cap_applied' => false,
                'critical_skill_readiness_cap' => null,
                'critical_skill_gap_count' => 1,
                'critical_skill_names' => [$roleSkills[0]->skill->name],
                'total_skills' => count($results),
                'met_skills' => $met,
                'skills_with_gap' => count($results) - $met,
            ]), 200),
        ]);
    }

    public function test_a_skill_data_change_with_a_primary_role_persists_readiness_and_intelligence(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->scenario();
        $profile->forceFill(['primary_career_role_id' => $role->id])->save();
        $this->fakeSkillGap($profile, $role, $roleSkills);

        // The same event the assessment submit dispatches.
        SkillDataChanged::dispatch($profile->fresh(), 'baseline_assessment_submit');

        $this->assertGreaterThan(0, ReadinessResult::where('student_profile_id', $profile->id)->count());
        $this->assertGreaterThan(
            0,
            DecisionSnapshot::where('student_profile_id', $profile->id)
                ->where('status', DecisionSnapshot::STATUS_SUCCEEDED)
                ->count(),
        );

        Sanctum::actingAs($user);

        // The two endpoints that used to answer 404.
        $this->getJson('/api/v1/readiness/latest')->assertOk();
        $this->getJson('/api/v1/intelligence/latest')->assertOk();
    }

    public function test_without_a_career_role_the_recalculation_stays_a_quiet_no_op(): void
    {
        [$user, $profile, $role, $roleSkills] = $this->scenario();
        $this->fakeSkillGap($profile, $role, $roleSkills);

        // No primary role and none passed: the listener aborts on
        // CAREER_ROLE_REQUIRED. It must NOT propagate — the assessment that
        // dispatched it has already committed.
        SkillDataChanged::dispatch($profile->fresh(), 'baseline_assessment_submit');

        $this->assertSame(0, ReadinessResult::where('student_profile_id', $profile->id)->count());
        Http::assertNothingSent();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/readiness/latest')->assertStatus(404);
        $this->getJson('/api/v1/intelligence/latest')->assertStatus(404);
    }
}
