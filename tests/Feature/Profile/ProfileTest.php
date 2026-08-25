<?php

namespace Tests\Feature\Profile;

use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

    private function learner(): User
    {
        $user = User::create([
            'name' => 'Learner One',
            'email' => 'learner@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'learner')->first()->id, ['organization_id' => null]);

        return $user;
    }

    public function test_non_learner_cannot_access_profile_endpoints(): void
    {
        $user = User::create([
            'name' => 'Company Rep',
            'email' => 'company@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', 'company_admin')->first()->id, ['organization_id' => null]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/profile')->assertStatus(403);
    }

    public function test_show_returns_404_when_no_profile_exists_yet(): void
    {
        Sanctum::actingAs($this->learner());

        $this->getJson('/api/v1/profile')->assertStatus(404);
    }

    public function test_store_creates_a_profile_and_computes_completeness(): void
    {
        Sanctum::actingAs($this->learner());

        $response = $this->postJson('/api/v1/profile', [
            'education' => 'BSc Computer Science',
            'specialization' => 'Software Engineering',
            'availability' => '10h/week',
            'visibility' => 'organization_only',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.education', 'BSc Computer Science')
            ->assertJsonPath('data.visibility', 'organization_only');

        // 3 of 6 completeness fields filled (education, specialization, availability) = 50%.
        $response->assertJsonPath('data.completeness_percent', 50);

        $this->assertDatabaseHas('student_profiles', [
            'education' => 'BSc Computer Science',
            'visibility' => 'organization_only',
        ]);
    }

    public function test_store_rejects_creating_a_second_profile(): void
    {
        $user = $this->learner();
        StudentProfile::create([
            'user_id' => $user->id,
            'visibility' => 'private',
            'consent_given' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/profile', [
            'education' => 'BSc CS',
            'specialization' => 'AI',
        ])->assertStatus(409);
    }

    public function test_store_requires_education_and_specialization(): void
    {
        Sanctum::actingAs($this->learner());

        $this->postJson('/api/v1/profile', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['education', 'specialization']);
    }

    public function test_update_only_touches_submitted_fields(): void
    {
        $user = $this->learner();
        StudentProfile::create([
            'user_id' => $user->id,
            'education' => 'BSc CS',
            'specialization' => 'AI',
            'availability' => '5h/week',
            'visibility' => 'private',
            'consent_given' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/profile', [
            'visibility' => 'public',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.visibility', 'public')
            // untouched fields survive the partial update.
            ->assertJsonPath('data.education', 'BSc CS')
            ->assertJsonPath('data.availability', '5h/week');
    }

    public function test_update_rejects_an_invalid_visibility_value(): void
    {
        $user = $this->learner();
        StudentProfile::create(['user_id' => $user->id, 'visibility' => 'private', 'consent_given' => true]);

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/profile', ['visibility' => 'everyone'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['visibility']);
    }

    public function test_update_returns_404_when_no_profile_exists_yet(): void
    {
        Sanctum::actingAs($this->learner());

        $this->putJson('/api/v1/profile', ['visibility' => 'public'])
            ->assertStatus(404);
    }
}
