<?php

namespace Tests\Feature\Profile;

use App\Events\SkillDataChanged;
use App\Models\CareerRole;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CareerGoalProfileTest extends TestCase
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
        StudentProfile::forceCreate(['user_id' => $user->id]);

        return $user;
    }

    public function test_selected_goal_is_returned_on_update_and_subsequent_profile_reads(): void
    {
        Event::fake([SkillDataChanged::class]);
        $user = $this->learner();
        $role = CareerRole::forceCreate([
            'title' => 'Data Analyst', 'slug' => 'data-analyst', 'version' => 2,
            'status' => 'approved', 'is_active' => true,
        ]);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/profile')->assertOk()->assertJsonPath('data.primary_career_role_id', null);
        $this->putJson('/api/v1/profile', ['primary_career_role_id' => $role->id])
            ->assertOk()->assertJsonPath('data.primary_career_role_id', $role->id);
        $this->getJson('/api/v1/profile')->assertOk()->assertJsonPath('data.primary_career_role_id', $role->id);
        $this->assertDatabaseHas('career_goal_history', [
            'student_profile_id' => $user->studentProfile->id,
            'career_role_id' => $role->id, 'career_role_version' => 2,
        ]);
        Event::assertDispatched(SkillDataChanged::class, fn ($event) => $event->source === 'career_role_change');

        $this->putJson('/api/v1/profile', ['primary_career_role_id' => $role->id])->assertOk();
        $this->assertDatabaseCount('career_goal_history', 1);
        Event::assertDispatchedTimes(SkillDataChanged::class, 1);

        Sanctum::actingAs($this->learner());
        $this->getJson('/api/v1/profile')->assertOk()->assertJsonPath('data.primary_career_role_id', null);
    }

    public function test_unavailable_goal_is_rejected_without_mutating_the_profile(): void
    {
        Event::fake([SkillDataChanged::class]);
        Sanctum::actingAs($this->learner());
        $role = CareerRole::forceCreate([
            'title' => 'Inactive Analyst', 'slug' => 'inactive-analyst', 'version' => 1,
            'status' => 'approved', 'is_active' => false,
        ]);
        $this->putJson('/api/v1/profile', ['primary_career_role_id' => $role->id])->assertUnprocessable();
        $this->getJson('/api/v1/profile')->assertOk()->assertJsonPath('data.primary_career_role_id', null);
        $this->assertDatabaseCount('career_goal_history', 0);
        Event::assertNotDispatched(SkillDataChanged::class);
    }
}
