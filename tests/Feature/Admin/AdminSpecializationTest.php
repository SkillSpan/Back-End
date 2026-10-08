<?php

namespace Tests\Feature\Admin;

use App\Models\CareerRole;
use App\Models\Role;
use App\Models\Skill;
use App\Models\Specialization;
use App\Models\User;
use Database\Seeders\SpecializationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin "Specializations" page and its session-authenticated endpoints:
 * list / create / edit / deactivate, the safe-delete guard, and managing the
 * career roles linked to a specialization through the existing pivot.
 */
class AdminSpecializationTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;

    private Role $learnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    // ------------------------------------------------ authorization

    public function test_a_guest_cannot_open_the_specializations_page(): void
    {
        $this->get('/admin/specializations')->assertRedirect(route('login'));
    }

    public function test_a_learner_cannot_open_the_specializations_page(): void
    {
        $this->actingAs($this->learner())->get('/admin/specializations')->assertStatus(403);
    }

    public function test_a_learner_cannot_call_the_specializations_api(): void
    {
        $this->actingAs($this->learner())->getJson('/admin/api/specializations')->assertStatus(403);
    }

    public function test_an_admin_can_open_the_specializations_page(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/specializations')
            ->assertOk()
            ->assertSee('Specializations');
    }

    // ------------------------------------------------ list

    public function test_the_list_returns_specializations_with_a_role_count(): void
    {
        $this->actingAs($this->admin());

        $specialization = $this->specialization('Software Engineering');
        $role = $this->careerRole('Backend Developer');
        $specialization->careerRoles()->attach($role->id);

        $response = $this->getJson('/admin/api/specializations')->assertOk();

        $row = collect($response->json('data.data'))->firstWhere('name', 'Software Engineering');

        $this->assertNotNull($row);
        $this->assertSame(1, $row['career_roles_count']);
        $this->assertTrue($row['is_active']);
    }

    // ------------------------------------------------ create / edit

    public function test_an_admin_can_create_a_specialization(): void
    {
        $this->actingAs($this->admin());

        $this->postJson('/admin/api/specializations', [
            'name' => 'Cloud Computing',
            'description' => 'Designing and operating cloud workloads.',
            'is_active' => true,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Cloud Computing')
            ->assertJsonPath('data.career_roles_count', 0);

        $this->assertDatabaseHas('specializations', ['name' => 'Cloud Computing', 'is_active' => true]);
    }

    public function test_a_duplicate_name_is_rejected(): void
    {
        $this->actingAs($this->admin());
        $this->specialization('Cloud Computing');

        $this->postJson('/admin/api/specializations', ['name' => 'Cloud Computing'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_a_name_is_required(): void
    {
        $this->actingAs($this->admin());

        $this->postJson('/admin/api/specializations', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_an_admin_can_update_a_specialization(): void
    {
        $this->actingAs($this->admin());
        $specialization = $this->specialization('Cloud Computing');

        $this->patchJson('/admin/api/specializations/'.$specialization->id, [
            'name' => 'Cloud & Infrastructure',
            'description' => 'Updated.',
            'is_active' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Cloud & Infrastructure');

        $this->assertDatabaseHas('specializations', ['id' => $specialization->id, 'name' => 'Cloud & Infrastructure']);
    }

    public function test_an_admin_can_deactivate_and_reactivate_a_specialization(): void
    {
        $this->actingAs($this->admin());
        $specialization = $this->specialization('Cloud Computing');

        $this->postJson('/admin/api/specializations/'.$specialization->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($specialization->fresh()->is_active);

        $this->postJson('/admin/api/specializations/'.$specialization->id.'/activate')
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue($specialization->fresh()->is_active);
    }

    // ------------------------------------------------ delete guard

    public function test_a_specialization_with_linked_roles_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin());

        $specialization = $this->specialization('Software Engineering');
        $role = $this->careerRole('Backend Developer');
        $specialization->careerRoles()->attach($role->id);

        $this->deleteJson('/admin/api/specializations/'.$specialization->id)
            ->assertStatus(409)
            ->assertJsonPath('code', 'SPECIALIZATION_IN_USE');

        $this->assertDatabaseHas('specializations', ['id' => $specialization->id]);
    }

    public function test_an_unused_specialization_can_be_deleted(): void
    {
        $this->actingAs($this->admin());
        $specialization = $this->specialization('Temporary Track');

        $this->deleteJson('/admin/api/specializations/'.$specialization->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('specializations', ['id' => $specialization->id]);
    }

    public function test_the_free_track_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin());

        $free = Specialization::create([
            'name' => SpecializationsSeeder::FREE_TRACK_NAME,
            'is_free_track' => true,
            'is_active' => true,
        ]);

        $this->deleteJson('/admin/api/specializations/'.$free->id)
            ->assertStatus(409)
            ->assertJsonPath('code', 'SPECIALIZATION_FREE_TRACK_PROTECTED');

        $this->assertDatabaseHas('specializations', ['id' => $free->id]);
    }

    // ------------------------------------------------ manage career roles

    public function test_manage_career_roles_lists_every_role_with_an_attached_flag(): void
    {
        $this->actingAs($this->admin());

        $specialization = $this->specialization('Software Engineering');
        $attached = $this->careerRole('Backend Developer');
        $other = $this->careerRole('Data Scientist');
        $specialization->careerRoles()->attach($attached->id);

        $roles = collect(
            $this->getJson('/admin/api/specializations/'.$specialization->id.'/career-roles')->assertOk()->json('data'),
        )->keyBy('title');

        $this->assertTrue($roles['Backend Developer']['attached']);
        $this->assertFalse($roles['Data Scientist']['attached']);
        $this->assertNotNull($other->id);
    }

    public function test_an_admin_can_sync_the_career_roles_of_a_specialization(): void
    {
        $this->actingAs($this->admin());

        $specialization = $this->specialization('Software Engineering');
        $first = $this->careerRole('Backend Developer');
        $second = $this->careerRole('Frontend Developer');
        $specialization->careerRoles()->attach($first->id);

        $this->putJson('/admin/api/specializations/'.$specialization->id.'/career-roles', [
            'role_ids' => [$second->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.career_roles_count', 1);

        $linked = $specialization->fresh()->careerRoles()->pluck('career_roles.id')->all();

        $this->assertSame([$second->id], $linked);
    }

    public function test_the_free_track_roles_cannot_be_synced(): void
    {
        $this->actingAs($this->admin());

        $free = Specialization::create([
            'name' => SpecializationsSeeder::FREE_TRACK_NAME,
            'is_free_track' => true,
            'is_active' => true,
        ]);
        $role = $this->careerRole('Backend Developer');

        $this->putJson('/admin/api/specializations/'.$free->id.'/career-roles', ['role_ids' => [$role->id]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'SPECIALIZATION_FREE_TRACK_PROTECTED');
    }

    // ------------------------------------------------ create career role

    public function test_an_admin_can_create_a_career_role_with_skills(): void
    {
        $this->actingAs($this->admin());
        $skill = Skill::create(['name' => 'Solidity', 'slug' => 'solidity', 'status' => 'active']);

        $this->postJson('/admin/api/specializations/career-roles', [
            'title' => 'Smart Contract Developer',
            'skill_ids' => [$skill->id],
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Smart Contract Developer')
            ->assertJsonPath('data.skills_count', 1);

        $this->assertDatabaseHas('career_roles', ['slug' => 'smart-contract-developer', 'status' => 'approved']);
    }

    public function test_a_duplicate_career_role_title_is_rejected(): void
    {
        $this->actingAs($this->admin());

        // The slug is what the service deduplicates on, so seed it exactly.
        CareerRole::forceCreate([
            'title' => 'Backend Developer',
            'slug' => 'backend-developer',
            'version' => 1,
            'status' => 'approved',
            'effective_date' => now()->toDateString(),
        ]);

        $this->postJson('/admin/api/specializations/career-roles', ['title' => 'Backend Developer'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CAREER_ROLE_TITLE_TAKEN');
    }

    public function test_the_skills_endpoint_returns_skill_options(): void
    {
        $this->actingAs($this->admin());
        Skill::create(['name' => 'Solidity', 'slug' => 'solidity', 'status' => 'active']);

        $names = array_column($this->getJson('/admin/api/specializations/skills')->assertOk()->json('data'), 'name');

        $this->assertContains('Solidity', $names);
    }

    // ------------------------------------------------ helpers

    private function admin(): User
    {
        $user = User::forceCreate([
            'name' => 'Platform Admin',
            'email' => uniqid().'admin@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($this->adminRole->id);

        return $user;
    }

    private function learner(): User
    {
        $user = User::forceCreate([
            'name' => 'Learner',
            'email' => uniqid().'learner@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($this->learnerRole->id);

        return $user;
    }

    private function specialization(string $name): Specialization
    {
        return Specialization::firstOrCreate(['name' => $name], ['is_active' => true]);
    }

    private function careerRole(string $title, string $status = 'approved'): CareerRole
    {
        return CareerRole::forceCreate([
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)).'-'.uniqid(),
            'version' => 1,
            'status' => $status,
            'effective_date' => now()->toDateString(),
        ]);
    }
}
