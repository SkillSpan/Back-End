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
        $user = User::forceCreate([
            'name' => 'Learner One',
            'email' => 'learner@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'learner')->first()->id, ['organization_id' => null]);

        return $user;
    }

    /**
     * university_name + student_university_number + specialization +
     * academic_level are required by the store contract; the rest are
     * optional completeness fields.
     */
    private function validPayload(): array
    {
        return [
            'university_name' => 'An-Najah National University',
            'student_university_number' => '202312345',
            'specialization' => 'Computer Science',
            'academic_level' => 'Third Year',
            'expected_graduation' => (int) now()->addYear()->format('Y'),
            'bio' => 'Aspiring backend engineer.',
            'availability' => '10h/week',
        ];
    }

    public function test_non_learner_cannot_access_profile_endpoints(): void
    {
        $user = User::forceCreate([
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
            'university_name' => 'An-Najah National University',
            'student_university_number' => '202312345',
            'specialization' => 'Computer Science',
            'academic_level' => 'Third Year',
            'availability' => '10h/week',
            'visibility' => 'organization_only',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.university_name', 'An-Najah National University')
            ->assertJsonPath('data.student_university_number', '202312345')
            ->assertJsonPath('data.specialization', 'Computer Science')
            ->assertJsonPath('data.academic_level', 'Third Year')
            ->assertJsonPath('data.visibility', 'organization_only');

        // 5 of 7 completeness fields filled (university_name,
        // student_university_number, specialization, academic_level,
        // availability) = 71%.
        $response->assertJsonPath('data.completeness_percent', 71);

        $this->assertDatabaseHas('student_profiles', [
            'university_name' => 'An-Najah National University',
            'student_university_number' => '202312345',
            'specialization' => 'Computer Science',
            'academic_level' => 'Third Year',
            'visibility' => 'organization_only',
        ]);
    }

    public function test_store_rejects_expected_graduation_year_out_of_range(): void
    {
        Sanctum::actingAs($this->learner());

        $payload = $this->validPayload();
        $payload['expected_graduation'] = 1999;

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

    public function test_store_updates_existing_profile_when_posted(): void
    {
        $user = $this->learner();
        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'visibility' => 'private',
            'consent_given' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/profile', $this->validPayload())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Learner profile saved successfully.')
            ->assertJsonPath('data.university_name', 'An-Najah National University')
            ->assertJsonPath('data.specialization', 'Computer Science');

        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $user->id,
            'university_name' => 'An-Najah National University',
            'student_university_number' => '202312345',
            'specialization' => 'Computer Science',
        ]);
    }

    public function test_store_requires_university_specialization_and_academic_level(): void
    {
        Sanctum::actingAs($this->learner());

        $this->postJson('/api/v1/profile', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['university_name', 'student_university_number', 'specialization', 'academic_level']);
    }

    public function test_update_only_touches_submitted_fields(): void
    {
        $user = $this->learner();
        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'university_name' => 'An-Najah National University',
            'student_university_number' => '202312345',
            'specialization' => 'Computer Science',
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
            ->assertJsonPath('data.university_name', 'An-Najah National University')
            ->assertJsonPath('data.availability', '5h/week');
    }

    public function test_update_can_set_and_clear_nullable_academic_fields(): void
    {
        $user = $this->learner();
        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'university_name' => 'An-Najah National University',
            'student_university_number' => '202312345',
            'specialization' => 'Computer Science',
            'academic_level' => 'Second Year',
            'visibility' => 'private',
            'consent_given' => true,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/profile', [
            'bio' => 'Updated bio.',
            'expected_graduation' => (int) now()->addYears(2)->format('Y'),
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

    public function test_update_can_update_text_academic_fields(): void
    {
        $user = $this->learner();
        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'university_name' => 'An-Najah National University',
            'student_university_number' => '202312345',
            'specialization' => 'Computer Science',
            'academic_level' => 'Second Year',
            'visibility' => 'private',
            'consent_given' => true,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/profile', [
            'university_name' => 'Birzeit University',
            'student_university_number' => '202398765',
            'specialization' => 'Software Engineering',
        ])->assertOk()
            ->assertJsonPath('data.university_name', 'Birzeit University')
            ->assertJsonPath('data.student_university_number', '202398765')
            ->assertJsonPath('data.specialization', 'Software Engineering');
    }

    public function test_update_rejects_an_invalid_visibility_value(): void
    {
        $user = $this->learner();
        StudentProfile::forceCreate(['user_id' => $user->id, 'visibility' => 'private', 'consent_given' => true]);

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
