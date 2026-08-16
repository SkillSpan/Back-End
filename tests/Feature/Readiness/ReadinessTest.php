<?php

namespace Tests\Feature\Readiness;

use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\ReadinessResult;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;
    private Role $companyRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        $this->companyRole = Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

    public function test_unauthenticated_user_is_rejected(): void
    {
        $this->postJson('/api/readiness/calculate', ['career_role_id' => 1])
            ->assertStatus(401);
    }

    public function test_organization_cannot_use_learner_readiness_endpoint(): void
    {
        $user = $this->createUserWithRole($this->companyRole);
        Sanctum::actingAs($user);

        $this->postJson('/api/readiness/calculate', ['career_role_id' => 1])
            ->assertStatus(403)
            ->assertJsonPath('code', 'LEARNER_ONLY');
    }

    public function test_successful_readiness_calculation_saves_result(): void
    {
        [$user, $profile, $careerRole, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        Http::fake([
            '*' => Http::response($this->successResponse($user->id, $careerRole->title, $roleSkills), 200),
        ]);

        $response = $this->postJson('/api/readiness/calculate', ['career_role_id' => $careerRole->id]);

        $response->assertOk()->assertJsonPath('data.score', 80.0);
        $this->assertDatabaseHas('readiness_results', [
            'student_profile_id' => $profile->id,
            'career_role_id' => $careerRole->id,
            'score' => 80.00,
        ]);
    }

    public function test_career_role_not_found_uses_custom_error_code(): void
    {
        [$user] = $this->createScenario();
        Sanctum::actingAs($user);

        $this->postJson('/api/readiness/calculate', ['career_role_id' => 99999])
            ->assertStatus(404)
            ->assertJsonPath('code', 'CAREER_ROLE_NOT_FOUND');
    }

    public function test_career_role_not_approved_uses_custom_error_code(): void
    {
        [$user, , $role] = $this->createScenario('draft');
        Sanctum::actingAs($user);

        $this->postJson('/api/readiness/calculate', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CAREER_ROLE_NOT_APPROVED');
    }

    public function test_career_role_without_skills_uses_custom_error_code(): void
    {
        $user = $this->createUserWithRole($this->learnerRole);
        $profile = StudentProfile::create(['user_id' => $user->id]);
        $role = CareerRole::create(['title' => 'Empty Role', 'slug' => 'empty-role-'.uniqid(), 'version' => 1, 'status' => 'approved']);
        $profile->update(['primary_career_role_id' => $role->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/readiness/calculate', ['career_role_id' => $role->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CAREER_ROLE_NO_SKILLS');
    }

    public function test_missing_skill_evaluation_blocks_fastapi_call(): void
    {
        [$user, , $careerRole, $roleSkills] = $this->createScenario();
        SkillEvaluation::query()->where('skill_id', $roleSkills[0]->skill_id)->delete();
        Sanctum::actingAs($user);
        Http::fake();

        $response = $this->postJson('/api/readiness/calculate', ['career_role_id' => $careerRole->id]);

        $response->assertStatus(422)->assertJsonPath('code', 'ASSESSMENT_INCOMPLETE');
        Http::assertNothingSent();
    }

    public function test_latest_skill_evaluation_is_used(): void
    {
        [$user, $profile, $careerRole, $roleSkills] = $this->createScenario();
        $skillId = $roleSkills[0]->skill_id;

        SkillEvaluation::create([
            'student_profile_id' => $profile->id,
            'skill_id' => $skillId,
            'level' => 1.0,
            'confidence' => 90,
            'algorithm_version' => 'old',
            'calculated_at' => now()->subDay(),
        ]);
        SkillEvaluation::create([
            'student_profile_id' => $profile->id,
            'skill_id' => $skillId,
            'level' => 4.5,
            'confidence' => 90,
            'algorithm_version' => 'new',
            'calculated_at' => now(),
        ]);

        Sanctum::actingAs($user);
        Http::fake(function ($request) use ($user, $careerRole, $roleSkills) {
            $skills = $request->data()['skills'];
            $sql = collect($skills)->firstWhere('skill_name', $roleSkills[0]->skill->name);
            self::assertSame(4.5, (float) $sql['current_level']);

            return Http::response($this->successResponse($user->id, $careerRole->title, $roleSkills), 200);
        });

        $this->postJson('/api/readiness/calculate', ['career_role_id' => $careerRole->id])->assertOk();
    }

    public function test_payload_contract_matches_fastapi_scale(): void
    {
        [$user, , $careerRole] = $this->createScenario();
        Sanctum::actingAs($user);

        Http::fake(['*' => Http::response($this->successResponse($user->id, $careerRole->title, $careerRole->roleSkills()->with('skill')->get()), 200)]);

        $this->postJson('/api/readiness/calculate', ['career_role_id' => $careerRole->id])->assertOk();

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $data['user_id'] > 0
                && $data['target_role'] === 'Data Analyst'
                && collect($data['skills'])->pluck('importance_weight')->sort()->values()->all() === [25.0, 30.0, 45.0];
        });
    }

    public function test_invalid_fastapi_skill_result_is_rejected(): void
    {
        [$user, $profile, $careerRole, $roleSkills] = $this->createScenario();
        Sanctum::actingAs($user);

        $response = $this->successResponse($user->id, $careerRole->title, $roleSkills);
        $response['skill_results'][1]['skill_name'] = $response['skill_results'][0]['skill_name'];

        Http::fake(['*' => Http::response($response, 200)]);

        $this->postJson('/api/readiness/calculate', ['career_role_id' => $careerRole->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'DATA_SCIENCE_INVALID_RESPONSE');

        $this->assertDatabaseMissing('readiness_results', ['student_profile_id' => $profile->id]);
    }

    public function test_fastapi_422_does_not_save_result(): void
    {
        [$user, $profile, $careerRole] = $this->createScenario();
        Sanctum::actingAs($user);
        Http::fake(['*' => Http::response(['detail' => 'invalid'], 422)]);

        $this->postJson('/api/readiness/calculate', ['career_role_id' => $careerRole->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DATA_SCIENCE_VALIDATION_ERROR');

        $this->assertDatabaseMissing('readiness_results', ['student_profile_id' => $profile->id]);
    }

    public function test_fastapi_500_does_not_save_result(): void
    {
        [$user, $profile, $careerRole] = $this->createScenario();
        Sanctum::actingAs($user);
        Http::fake(['*' => Http::response(['message' => 'server error'], 500)]);

        $this->postJson('/api/readiness/calculate', ['career_role_id' => $careerRole->id])
            ->assertStatus(503)
            ->assertJsonPath('code', 'DATA_SCIENCE_SERVICE_ERROR');

        $this->assertDatabaseMissing('readiness_results', ['student_profile_id' => $profile->id]);
    }

    public function test_fastapi_timeout_does_not_save_result(): void
    {
        [$user, $profile, $careerRole] = $this->createScenario();
        Sanctum::actingAs($user);
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->postJson('/api/readiness/calculate', ['career_role_id' => $careerRole->id])
            ->assertStatus(503)
            ->assertJsonPath('code', 'DATA_SCIENCE_UNAVAILABLE');

        $this->assertDatabaseMissing('readiness_results', ['student_profile_id' => $profile->id]);
    }

    public function test_latest_endpoint_returns_latest_result(): void
    {
        [$user, $profile, $careerRole] = $this->createScenario();
        ReadinessResult::create([
            'student_profile_id' => $profile->id,
            'career_role_id' => $careerRole->id,
            'career_role_version' => 1,
            'score' => 70,
            'skill_match_component' => 70,
            'practical_experience_component' => null,
            'assessment_reliability_component' => null,
            'profile_completeness_component' => null,
            'critical_cap_applied' => false,
            'band' => null,
            'algorithm_version' => 'test',
            'calculated_at' => now()->subMinute(),
            'snapshot' => [],
        ]);
        ReadinessResult::create([
            'student_profile_id' => $profile->id,
            'career_role_id' => $careerRole->id,
            'career_role_version' => 1,
            'score' => 82,
            'skill_match_component' => 82,
            'practical_experience_component' => null,
            'assessment_reliability_component' => null,
            'profile_completeness_component' => null,
            'critical_cap_applied' => false,
            'band' => null,
            'algorithm_version' => 'test',
            'calculated_at' => now(),
            'snapshot' => [],
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/readiness/latest?career_role_id='.$careerRole->id)
            ->assertOk()
            ->assertJsonPath('data.score', 82.0);
    }

    private function createScenario(string $status = 'approved'): array
    {
        $user = $this->createUserWithRole($this->learnerRole);
        $role = CareerRole::create([
            'title' => 'Data Analyst',
            'slug' => 'data-analyst-'.uniqid(),
            'version' => 1,
            'status' => $status,
        ]);

        $profile = StudentProfile::create([
            'user_id' => $user->id,
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
            $roleSkill = CareerRoleSkill::create([
                'career_role_id' => $role->id,
                'skill_id' => $skill->id,
                'required_level' => $required,
                'importance_weight' => $weight,
                'is_critical' => $critical,
            ]);
            SkillEvaluation::create([
                'student_profile_id' => $profile->id,
                'skill_id' => $skill->id,
                'level' => $current,
                'confidence' => 90,
                'algorithm_version' => 'test-v1',
                'calculated_at' => now()->addMicroseconds(rand(1, 1000)),
            ]);
            $roleSkills->push($roleSkill->load('skill'));
        }

        return [$user, $profile, $role, $roleSkills];
    }

    private function createUserWithRole(Role $role): User
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function successResponse(int $userId, string $title, $roleSkills): array
    {
        return [
            'user_id' => $userId,
            'target_role' => $title,
            'base_readiness_score' => 80.0,
            'readiness_score' => 80.0,
            'critical_skill_cap_applied' => false,
            'critical_skill_readiness_cap' => null,
            'critical_skill_gap_count' => 0,
            'critical_skill_names' => [],
            'total_skills' => $roleSkills->count(),
            'met_skills' => 1,
            'skills_with_gap' => 2,
            'skill_results' => $roleSkills->map(fn ($roleSkill) => [
                'skill_name' => $roleSkill->skill->name,
                'current_level' => 3.0,
                'required_level' => (float) $roleSkill->required_level,
                'importance_weight' => (float) $roleSkill->importance_weight * 100,
                'is_critical' => (bool) $roleSkill->is_critical,
                'gap' => 1.0,
                'status' => 'gap',
            ])->values()->all(),
        ];
    }
}
