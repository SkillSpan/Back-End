<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

    public function test_individual_registration_success(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'user_type' => 'individual',
            'name' => 'أحمد محمد',
            'email' => 'ahmed@test.com',
            'phone' => '966501234567',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
            'privacy_accepted' => true,
            // Academic fields (university/specialization) are collected
            // later via POST /api/v1/profile as Foreign Keys — the
            // registration payload only seeds a bare profile row.
            'career_status' => 'طالب',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'ahmed@test.com',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('student_profiles', [
            'user_id' => User::where('email', 'ahmed@test.com')->first()->id,
            'career_status' => 'طالب',
        ]);
        $this->assertNotNull(User::where('email', 'ahmed@test.com')->first()->terms_accepted_at);
        $this->assertNotNull(User::where('email', 'ahmed@test.com')->first()->privacy_accepted_at);
    }

    public function test_organization_registration_success(): void
    {
        $file = UploadedFile::fake()->image('proof.jpg');

        $response = $this->postJson('/api/v1/auth/register/organization', [
            'name' => 'أحمد المدير',
            'email' => 'admin@company.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
            'privacy_accepted' => true,
            'organization_name' => 'شركة التقنية',
            'organization_type' => 'company',
            'organization_contact_email' => 'info@company.com',
            'organization_contact_phone' => '966501234567',
            'organization_website' => 'https://company.com',
            'organization_description' => 'شركة تقنية رائدة',
            'proof_file' => $file,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('organizations', [
            'name' => 'شركة التقنية',
            'verification_status' => 'pending',
        ]);
        $this->assertDatabaseHas('uploaded_files', [
            'type' => 'certificate',
            'status' => 'pending',
        ]);
    }

    public function test_registration_fails_duplicate_email(): void
    {
        User::create([
            'name' => 'Test User',
            'email' => 'duplicate@test.com',
            'password' => Hash::make('password123'),
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'user_type' => 'individual',
            'name' => 'Test User',
            'email' => 'duplicate@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_registration_fails_missing_terms(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'user_type' => 'individual',
            'name' => 'Test User',
            'email' => 'test@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => false,
            'privacy_accepted' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['terms_accepted']);
    }

    public function test_organization_registration_fails_without_proof_file(): void
    {
        $response = $this->postJson('/api/v1/auth/register/organization', [
            'name' => 'Test Admin',
            'email' => 'admin@company.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
            'privacy_accepted' => true,
            'organization_name' => 'شركة التقنية',
            'organization_type' => 'company',
            'organization_contact_email' => 'info@company.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['proof_file']);
    }
}
