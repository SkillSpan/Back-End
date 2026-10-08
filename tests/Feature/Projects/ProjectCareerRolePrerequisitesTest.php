<?php

namespace Tests\Feature\Projects;

use App\Models\CareerRole;
use App\Models\Project;
use App\Models\ProjectRequiredSkill;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * US-MATCH-DATA-03 prerequisites.
 *
 *   Project → Career Role → approved Role → Skill mapping
 *           → Project Required Skills → Minimum Required Level (0–5, decimals)
 *
 * Covers the three backend prerequisites only:
 *   1. a project must be linked to a real career role;
 *   2. every project skill must belong to that career role's approved mapping;
 *   3. every required skill must carry a minimum required level (0–5, decimals).
 *
 * The matching / eligibility / intelligence behaviour is deliberately NOT
 * re-tested here — it is covered by the existing suites and must not change.
 */
class ProjectCareerRolePrerequisitesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
        Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function admin(): User
    {
        $existing = User::where('email', 'admin@test.com')->first();

        if ($existing !== null) {
            return $existing;
        }

        $user = User::forceCreate([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'admin')->first()->id);

        return $user->fresh();
    }

    private function skill(string $name): Skill
    {
        return Skill::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.uniqid(),
            'category' => 'backend',
            'status' => 'active',
        ]);
    }

    /**
     * A career role with an explicit approved skill mapping.
     *
     * @param  array<int, Skill>  $skills
     */
    private function careerRole(string $title, array $skills): CareerRole
    {
        $role = CareerRole::create([
            'title' => $title,
            'slug' => Str::slug($title).'-'.uniqid(),
            'version' => 1,
            'status' => 'approved',
        ]);

        foreach ($skills as $skill) {
            $role->roleSkills()->create([
                'skill_id' => $skill->id,
                'required_level' => 3,
                'importance_weight' => 1,
                'is_critical' => true,
            ]);
        }

        return $role;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CareerRole $role, array $requiredSkills, array $overrides = []): array
    {
        return array_merge([
            'type' => 'simulation',
            'title' => 'Career Role Prerequisite Project',
            'description' => 'Proves the Project → Career Role → Skill mapping chain.',
            'objectives' => 'Ship the prerequisite.',
            'capacity' => 2,
            'confidentiality' => 'public',
            'career_role_id' => $role->id,
            'required_skills' => $requiredSkills,
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // TASK 1 — Project → Career Role
    // -----------------------------------------------------------------

    public function test_a_project_requires_a_career_role(): void
    {
        Sanctum::actingAs($this->admin());

        $role = $this->careerRole('Backend Developer', [$this->skill('Laravel')]);

        $this->postJson('/api/v1/projects', $this->payload(
            $role,
            [['skill_id' => $role->skills()->first()->id, 'minimum_required_level' => 3]],
            ['career_role_id' => null],
        ))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['career_role_id']);

        $this->assertSame(0, Project::count());
    }

    public function test_a_project_rejects_a_career_role_that_does_not_exist(): void
    {
        Sanctum::actingAs($this->admin());

        $role = $this->careerRole('Backend Developer', [$this->skill('Laravel')]);

        $this->postJson('/api/v1/projects', $this->payload(
            $role,
            [['skill_id' => $role->skills()->first()->id, 'minimum_required_level' => 3]],
            ['career_role_id' => 999999],
        ))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['career_role_id']);

        $this->assertSame(0, Project::count());
    }

    public function test_a_project_with_a_valid_career_role_is_persisted_and_linked(): void
    {
        Sanctum::actingAs($this->admin());

        $laravel = $this->skill('Laravel');
        $role = $this->careerRole('Backend Developer', [$laravel]);

        $response = $this->postJson('/api/v1/projects', $this->payload(
            $role,
            [['skill_id' => $laravel->id, 'minimum_required_level' => 3]],
        ))->assertStatus(201)
            ->assertJsonPath('data.career_role_id', $role->id)
            ->assertJsonPath('data.career_role.id', $role->id)
            ->assertJsonPath('data.career_role.title', 'Backend Developer');

        $project = Project::find($response->json('data.id'));

        // Foreign key persisted, and both Eloquent sides of the relation work.
        $this->assertSame($role->id, $project->career_role_id);
        $this->assertTrue($project->careerRole->is($role));
        $this->assertTrue($role->fresh()->projects->contains($project));
    }

    public function test_updating_a_project_rejects_an_invalid_career_role(): void
    {
        Sanctum::actingAs($this->admin());

        $laravel = $this->skill('Laravel');
        $role = $this->careerRole('Backend Developer', [$laravel]);

        $projectId = $this->postJson('/api/v1/projects', $this->payload(
            $role,
            [['skill_id' => $laravel->id, 'minimum_required_level' => 3]],
        ))->assertStatus(201)->json('data.id');

        $this->patchJson("/api/v1/projects/{$projectId}", ['career_role_id' => 999999])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['career_role_id']);

        $this->assertSame($role->id, Project::find($projectId)->career_role_id);
    }

    // -----------------------------------------------------------------
    // TASK 2 — Career Role → Skill mapping enforcement
    // -----------------------------------------------------------------

    public function test_valid_role_with_valid_mapped_skills_succeeds(): void
    {
        Sanctum::actingAs($this->admin());

        $laravel = $this->skill('Laravel');
        $role = $this->careerRole('Backend Developer', [$laravel]);

        $this->postJson('/api/v1/projects', $this->payload(
            $role,
            [['skill_id' => $laravel->id, 'minimum_required_level' => 3]],
        ))
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertSame(1, Project::count());
        $this->assertSame(1, ProjectRequiredSkill::count());
    }

    public function test_a_skill_that_is_not_mapped_to_the_career_role_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $laravel = $this->skill('Laravel');
        $python = $this->skill('Python');
        $role = $this->careerRole('Backend Developer', [$laravel]);

        // Python exists globally but is NOT mapped to Backend Developer.
        $this->postJson('/api/v1/projects', $this->payload(
            $role,
            [['skill_id' => $python->id, 'minimum_required_level' => 3]],
        ))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['required_skills.0.skill_id']);

        $this->assertSame(0, Project::count());
    }

    public function test_one_invalid_skill_rejects_the_whole_request(): void
    {
        Sanctum::actingAs($this->admin());

        $laravel = $this->skill('Laravel');
        $python = $this->skill('Python');
        $role = $this->careerRole('Backend Developer', [$laravel]);

        $this->postJson('/api/v1/projects', $this->payload($role, [
            ['skill_id' => $laravel->id, 'minimum_required_level' => 3],
            ['skill_id' => $python->id, 'minimum_required_level' => 2],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['required_skills.1.skill_id']);

        // Nothing was persisted — the valid skill was not accepted either.
        $this->assertSame(0, Project::count());
        $this->assertSame(0, ProjectRequiredSkill::count());
    }

    public function test_updating_a_project_rejects_an_unmapped_skill(): void
    {
        Sanctum::actingAs($this->admin());

        $laravel = $this->skill('Laravel');
        $python = $this->skill('Python');
        $role = $this->careerRole('Backend Developer', [$laravel]);

        $projectId = $this->postJson('/api/v1/projects', $this->payload(
            $role,
            [['skill_id' => $laravel->id, 'minimum_required_level' => 3]],
        ))->assertStatus(201)->json('data.id');

        $this->patchJson("/api/v1/projects/{$projectId}", [
            'required_skills' => [
                ['skill_id' => $python->id, 'minimum_required_level' => 2],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['required_skills.0.skill_id']);

        // The original skill survived the rejected update.
        $this->assertSame(
            [$laravel->id],
            ProjectRequiredSkill::where('project_id', $projectId)->pluck('skill_id')->all()
        );
    }

    public function test_changing_the_career_role_away_from_the_attached_skills_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $laravel = $this->skill('Laravel');
        $django = $this->skill('Django');

        $backend = $this->careerRole('Backend Developer', [$laravel]);
        $pythonDev = $this->careerRole('Python Developer', [$django]);

        $projectId = $this->postJson('/api/v1/projects', $this->payload(
            $backend,
            [['skill_id' => $laravel->id, 'minimum_required_level' => 3]],
        ))->assertStatus(201)->json('data.id');

        // Switching the role WITHOUT resubmitting skills must not leave the
        // project requiring a skill that the new role does not map.
        $this->patchJson("/api/v1/projects/{$projectId}", ['career_role_id' => $pythonDev->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_SKILL_NOT_IN_CAREER_ROLE');

        $this->assertSame($backend->id, Project::find($projectId)->career_role_id);
    }

    // -----------------------------------------------------------------
    // TASK 3 — Minimum Required Level (0–5, decimals)
    // -----------------------------------------------------------------

    public function test_a_required_skill_without_a_minimum_level_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $laravel = $this->skill('Laravel');
        $role = $this->careerRole('Backend Developer', [$laravel]);

        $this->postJson('/api/v1/projects', $this->payload(
            $role,
            [['skill_id' => $laravel->id]],
        ))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['required_skills.0.minimum_required_level']);

        $this->assertSame(0, Project::count());
    }

    public function test_a_minimum_level_of_zero_is_valid(): void
    {
        $this->assertMinimumLevelIsAccepted(0, 0.0);
    }

    public function test_a_minimum_level_of_five_is_valid(): void
    {
        $this->assertMinimumLevelIsAccepted(5, 5.0);
    }

    public function test_a_decimal_minimum_level_is_valid(): void
    {
        $this->assertMinimumLevelIsAccepted(3.5, 3.5);
        $this->assertMinimumLevelIsAccepted(4.25, 4.25);
    }

    public function test_a_minimum_level_below_zero_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $laravel = $this->skill('Laravel');
        $role = $this->careerRole('Backend Developer', [$laravel]);

        $this->postJson('/api/v1/projects', $this->payload(
            $role,
            [['skill_id' => $laravel->id, 'minimum_required_level' => -0.5]],
        ))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['required_skills.0.minimum_required_level']);

        $this->assertSame(0, Project::count());
    }

    public function test_a_minimum_level_above_five_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $laravel = $this->skill('Laravel');
        $role = $this->careerRole('Backend Developer', [$laravel]);

        $this->postJson('/api/v1/projects', $this->payload(
            $role,
            [['skill_id' => $laravel->id, 'minimum_required_level' => 5.25]],
        ))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['required_skills.0.minimum_required_level']);

        $this->assertSame(0, Project::count());
    }

    public function test_the_legacy_minimum_level_field_name_is_still_accepted(): void
    {
        Sanctum::actingAs($this->admin());

        $laravel = $this->skill('Laravel');
        $role = $this->careerRole('Backend Developer', [$laravel]);

        $this->postJson('/api/v1/projects', $this->payload(
            $role,
            [['skill_id' => $laravel->id, 'minimum_level' => 4.5]],
        ))->assertStatus(201);

        $this->assertDatabaseHas('project_required_skills', [
            'skill_id' => $laravel->id,
            'minimum_level' => 4.5,
        ]);
    }

    public function test_the_minimum_required_level_is_persisted_as_a_decimal(): void
    {
        Sanctum::actingAs($this->admin());

        $laravel = $this->skill('Laravel');
        $role = $this->careerRole('Backend Developer', [$laravel]);

        $projectId = $this->postJson('/api/v1/projects', $this->payload(
            $role,
            [['skill_id' => $laravel->id, 'minimum_required_level' => 3.75, 'is_critical_entry' => true]],
        ))->assertStatus(201)
            ->assertJsonPath('data.required_skills.0.minimum_required_level', 3.75)
            ->assertJsonPath('data.required_skills.0.minimum_level', 3.75)
            ->json('data.id');

        $this->assertDatabaseHas('project_required_skills', [
            'project_id' => $projectId,
            'skill_id' => $laravel->id,
            'minimum_level' => 3.75,
            'is_critical_entry' => true,
        ]);

        $this->assertSame(3.75, ProjectRequiredSkill::where('project_id', $projectId)->first()->minimum_level);
    }

    /**
     * Post a project requiring one skill at $level and assert it is accepted
     * and stored as $expected.
     */
    private function assertMinimumLevelIsAccepted(float $level, float $expected): void
    {
        Sanctum::actingAs($this->admin());

        $laravel = $this->skill('Laravel');
        $role = $this->careerRole('Backend Developer', [$laravel]);

        $projectId = $this->postJson('/api/v1/projects', $this->payload(
            $role,
            [['skill_id' => $laravel->id, 'minimum_required_level' => $level]],
        ))->assertStatus(201)->json('data.id');

        $stored = ProjectRequiredSkill::where('project_id', $projectId)->first();

        $this->assertNotNull($stored);
        $this->assertSame($expected, $stored->minimum_level);
    }
}
