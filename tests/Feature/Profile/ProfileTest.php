<?php

namespace Tests\Feature\Profile;

use App\Models\Role;
use App\Models\Specialization;
use App\Models\StudentProfile;
use App\Models\University;
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

    private function university(): University
    {
        return University::create(['name' => 'An-Najah National University']);
    }

    private function specialization(): Specialization
    {
        return Specialization::create(['name' => 'Software Engineering']);
    }

    /**
     * university_id + specialization_id + academic_level are required by
     * the store contract; availability is an optional completeness field.
     */
    private function validPayload(): array
    {
        return [
            'university_id' => $this->university()->id,
            'specialization_id' => $this->specialization()->id,
            'academic_level' => 'Third Year',
            'expected_graduation' => now()->addYear()->toDateString(),
            'bio' => 'Aspiring backend engineer.',
            'availability' => '10h/week',
        ];
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

        $universityId = $this->university()->id;
        $specializationId = $this->specialization()->id;

        $response = $this->postJson('/api/v1/profile', [
            'university_id' => $universityId,
            'specialization_id' => $specializationId,
            'academic_level' => 'Third Year',
            'availability' => '10h/week',
            'visibility' => 'organization_only',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.university.id', $universityId)
            ->assertJsonPath('data.university.name', 'An-Najah National University')
            ->assertJsonPath('data.specialization.id', $specializationId)
            ->assertJsonPath('data.academic_level', 'Third Year')
            ->assertJsonPath('data.visibility', 'organization_only');

        // 4 of 6 completeness fields filled (university, specialization,
        // academic_level, availability) = 67%.
        $response->assertJsonPath('data.completeness_percent', 67);

        $this->assertDatabaseHas('student_profiles', [
            'university_id' => $universityId,
            'specialization_id' => $specializationId,
            'academic_level' => 'Third Year',
            'visibility' => 'organization_only',
        ]);
    }

    public function test_store_rejects_unknown_or_inactive_university_and_specialization(): void
    {
        Sanctum::actingAs($this->learner());
        $inactive = University::create(['name' => 'Closed University', 'is_active' => false]);
        $specializationId = $this->specialization()->id;

        $this->postJson('/api/v1/profile', [
            'university_id' => 999999,
            'specialization_id' => $specializationId,
            'academic_level' => 'First Year',
        ])->assertStatus(422)->assertJsonValidationErrors(['university_id']);

        // Soft-blocked (is_active = false) references behave like unknown ones.
        $this->postJson('/api/v1/profile', [
            'university_id' => $inactive->id,
            'specialization_id' => $specializationId,
            'academic_level' => 'First Year',
        ])->assertStatus(422)->assertJsonValidationErrors(['university_id']);
    }

    public function test_store_validates_expected_graduation_is_not_in_the_past(): void
    {
        Sanctum::actingAs($this->learner());

        $payload = $this->validPayload();
        $payload['expected_graduation'] = now()->subYear()->toDateString();

        $this->postJson('/api/v1/profile', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expected_graduation']);
    }

    public function test_store_caps_bio_length(): void
    {
        Sanctum::actingAs($this->learner());

        $payload = $this->validPayload();
        $payload['bio'] = str_repeat('a', 2001);

        $this->postJson('/api/v1/profile', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bio']);
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

        $this->postJson('/api/v1/profile', $this->validPayload())->assertStatus(409);
    }

    public function test_store_requires_university_specialization_and_academic_level(): void
    {
        Sanctum::actingAs($this->learner());

        $this->postJson('/api/v1/profile', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['university_id', 'specialization_id', 'academic_level']);
    }

    public function test_update_only_touches_submitted_fields(): void
    {
        $user = $this->learner();
        StudentProfile::create([
            'user_id' => $user->id,
            'university_id' => $this->university()->id,
            'specialization_id' => $this->specialization()->id,
            'academic_level' => 'Second Year',
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
            ->assertJsonPath('data.university.name', 'An-Najah National University')
            ->assertJsonPath('data.availability', '5h/week');
    }

    public function test_update_can_set_and_clear_nullable_academic_fields(): void
    {
        $user = $this->learner();
        StudentProfile::create([
            'user_id' => $user->id,
            'university_id' => $this->university()->id,
            'specialization_id' => $this->specialization()->id,
            'academic_level' => 'Second Year',
            'visibility' => 'private',
            'consent_given' => true,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/profile', [
            'bio' => 'Updated bio.',
            'expected_graduation' => now()->addMonths(18)->toDateString(),
        ])->assertOk()
            ->assertJsonPath('data.bio', 'Updated bio.');

        // Sending null is an explicit "clear this field".
        $this->putJson('/api/v1/profile', [
            'bio' => null,
            'expected_graduation' => null,
        ])->assertOk()
            ->assertJsonPath('data.bio', null)
            ->assertJsonPath('data.expected_graduation', null);
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
