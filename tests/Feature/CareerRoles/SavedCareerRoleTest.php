<?php

namespace Tests\Feature\CareerRoles;

use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\Role;
use App\Models\SavedCareerRole;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SavedCareerRoleTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->getJson('/api/v1/saved-career-roles')->assertStatus(401);
        $this->putJson('/api/v1/saved-career-roles/1')->assertStatus(401);
        $this->deleteJson('/api/v1/saved-career-roles/1')->assertStatus(401);
    }

    public function test_the_list_is_an_empty_array_before_anything_is_saved(): void
    {
        Sanctum::actingAs($this->learner());

        $response = $this->getJson('/api/v1/saved-career-roles')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSame([], $response->json('data'));
    }

    public function test_saving_a_role_returns_it_in_the_list(): void
    {
        $role = $this->approvedRole('Data Analyst');
        $this->attachSkill($role, 'SQL');

        Sanctum::actingAs($this->learner());

        $this->putJson('/api/v1/saved-career-roles/'.$role->id)
            ->assertStatus(200)
            ->assertJsonPath('data.career_role_id', $role->id)
            ->assertJsonPath('data.is_saved', true);

        $response = $this->getJson('/api/v1/saved-career-roles')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $role->id)
            ->assertJsonPath('data.0.title', 'Data Analyst')
            ->assertJsonPath('data.0.is_saved', true)
            ->assertJsonPath('data.0.skills_count', 1);

        $this->assertIsArray($response->json('data'));
        $this->assertArrayHasKey(0, $response->json('data'));
    }

    public function test_saving_the_same_role_twice_keeps_one_row(): void
    {
        $role = $this->approvedRole('Data Analyst');
        $user = $this->learner();

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/saved-career-roles/'.$role->id)->assertStatus(200);
        $this->putJson('/api/v1/saved-career-roles/'.$role->id)->assertStatus(200);

        $this->assertSame(1, SavedCareerRole::query()->where('user_id', $user->id)->count());
    }

    public function test_removing_a_role_empties_the_list(): void
    {
        $role = $this->approvedRole('Data Analyst');
        $user = $this->learner();

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/saved-career-roles/'.$role->id)->assertStatus(200);

        $this->deleteJson('/api/v1/saved-career-roles/'.$role->id)
            ->assertStatus(200)
            ->assertJsonPath('data.is_saved', false);

        $this->assertSame(0, SavedCareerRole::query()->where('user_id', $user->id)->count());
        $this->assertSame([], $this->getJson('/api/v1/saved-career-roles')->json('data'));
    }

    public function test_removing_a_role_that_was_never_saved_is_not_an_error(): void
    {
        $role = $this->approvedRole('Data Analyst');

        Sanctum::actingAs($this->learner());

        $this->deleteJson('/api/v1/saved-career-roles/'.$role->id)
            ->assertStatus(200)
            ->assertJsonPath('data.is_saved', false);
    }

    public function test_an_unknown_role_is_a_404(): void
    {
        Sanctum::actingAs($this->learner());

        $this->putJson('/api/v1/saved-career-roles/999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'CAREER_ROLE_NOT_FOUND');
    }

    public function test_a_role_that_is_not_approved_is_a_422(): void
    {
        $role = $this->role('Draft Role', 'draft');

        Sanctum::actingAs($this->learner());

        $this->putJson('/api/v1/saved-career-roles/'.$role->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'CAREER_ROLE_NOT_APPROVED');
    }

    public function test_saved_roles_are_per_user(): void
    {
        $role = $this->approvedRole('Data Analyst');
        $owner = $this->learner();
        $other = $this->learner();

        Sanctum::actingAs($owner);
        $this->putJson('/api/v1/saved-career-roles/'.$role->id)->assertStatus(200);

        Sanctum::actingAs($other);
        $this->assertSame([], $this->getJson('/api/v1/saved-career-roles')->json('data'));
    }

    public function test_a_role_that_stops_being_selectable_leaves_the_list(): void
    {
        $role = $this->approvedRole('Data Analyst');
        $user = $this->learner();

        Sanctum::actingAs($user);
        $this->putJson('/api/v1/saved-career-roles/'.$role->id)->assertStatus(200);

        $role->forceFill(['is_active' => false])->save();

        $this->assertSame([], $this->getJson('/api/v1/saved-career-roles')->json('data'));
        $this->assertSame(1, SavedCareerRole::query()->where('user_id', $user->id)->count());
    }

    private function learner(): User
    {
        $user = User::forceCreate([
            'name' => 'Learner',
            'email' => uniqid().'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($this->learnerRole->id);

        return $user;
    }

    private function approvedRole(string $title): CareerRole
    {
        return $this->role($title, 'approved');
    }

    private function role(string $title, string $status): CareerRole
    {
        return CareerRole::forceCreate([
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)).'-'.uniqid(),
            'version' => 1,
            'status' => $status,
            'effective_date' => now()->toDateString(),
        ]);
    }

    private function attachSkill(CareerRole $role, string $name): void
    {
        $skill = Skill::create([
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(),
            'category' => 'technical',
        ]);

        CareerRoleSkill::create([
            'career_role_id' => $role->id,
            'skill_id' => $skill->id,
            'required_level' => 3.0,
            'importance_weight' => 0.5,
            'is_critical' => false,
        ]);
    }
}
