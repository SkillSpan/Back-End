<?php

namespace Tests\Feature;

use App\Models\CareerRole;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SavedCareerRoleTest extends TestCase
{
    use RefreshDatabase;

    private function learner(): User
    {
        $user = User::forceCreate([
            'name' => 'Learner', 'email' => uniqid().'@example.com',
            'password' => 'password123', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        $role = Role::firstOrCreate(['slug' => 'learner'], ['name' => 'Learner', 'description' => '']);
        $user->roles()->attach($role);

        return $user;
    }

    private function careerRole(array $attributes = []): CareerRole
    {
        return CareerRole::forceCreate(array_merge([
            'title' => 'Data Analyst', 'slug' => uniqid('analyst-'),
            'version' => 1, 'status' => 'approved', 'is_active' => true,
        ], $attributes));
    }

    public function test_bookmarks_persist_idempotently_without_changing_the_career_goal(): void
    {
        $user = $this->learner();
        $goal = $this->careerRole();
        $bookmark = $this->careerRole();
        $profile = StudentProfile::forceCreate(['user_id' => $user->id, 'primary_career_role_id' => $goal->id]);
        Sanctum::actingAs($user);

        $url = '/api/v1/saved-career-roles/'.$bookmark->id;
        $this->putJson($url)->assertOk()->assertJsonPath('data.is_saved', true);
        $this->putJson($url)->assertOk();
        $this->assertDatabaseCount('saved_career_roles', 1);
        $this->getJson('/api/v1/saved-career-roles')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $bookmark->id);
        $this->assertEquals($goal->id, $profile->fresh()->primary_career_role_id);

        $this->deleteJson($url)->assertOk()->assertJsonPath('data.is_saved', false);
        $this->deleteJson($url)->assertOk();
        $this->assertDatabaseCount('saved_career_roles', 0);
    }

    public function test_a_learner_cannot_read_or_remove_another_learners_bookmarks(): void
    {
        $owner = $this->learner();
        $other = $this->learner();
        $role = $this->careerRole();
        $url = '/api/v1/saved-career-roles/'.$role->id;
        Sanctum::actingAs($owner);
        $this->putJson($url)->assertOk();
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/saved-career-roles')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson($url)->assertOk();
        $this->assertDatabaseHas('saved_career_roles', ['user_id' => $owner->id, 'career_role_id' => $role->id]);
    }

    public function test_only_active_approved_roles_can_be_saved_but_inactive_bookmarks_can_be_removed(): void
    {
        Sanctum::actingAs($this->learner());
        foreach ([['status' => 'draft'], ['is_active' => false]] as $attributes) {
            $role = $this->careerRole($attributes);
            $this->putJson('/api/v1/saved-career-roles/'.$role->id)->assertNotFound();
        }
        $role = $this->careerRole();
        $url = '/api/v1/saved-career-roles/'.$role->id;
        $this->putJson($url)->assertOk();
        $role->forceFill(['is_active' => false])->save();
        $this->getJson('/api/v1/saved-career-roles')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson($url)->assertOk();
        $this->assertDatabaseCount('saved_career_roles', 0);
    }

    public function test_authentication_and_learner_role_are_required(): void
    {
        $role = $this->careerRole();
        $url = '/api/v1/saved-career-roles/'.$role->id;
        $this->getJson('/api/v1/saved-career-roles')->assertUnauthorized();
        $this->putJson($url)->assertUnauthorized();
        $this->deleteJson($url)->assertUnauthorized();
        $user = $this->learner();
        $user->roles()->detach();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/saved-career-roles')->assertForbidden();
        $this->putJson($url)->assertForbidden();
        $this->deleteJson($url)->assertForbidden();
    }
}
