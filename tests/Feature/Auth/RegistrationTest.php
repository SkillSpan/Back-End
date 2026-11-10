<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
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
            'email' => 'ahmed@example.com',
            'phone' => '966501234567',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
            'privacy_accepted' => true,
            'career_status' => 'طالب',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'ahmed@example.com',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('student_profiles', [
            'user_id' => User::where('email', 'ahmed@example.com')->first()->id,
            'career_status' => 'طالب',
            'completeness_percent' => 0,
        ]);
        $this->assertNotNull(User::where('email', 'ahmed@example.com')->first()->terms_accepted_at);
        $this->assertNotNull(User::where('email', 'ahmed@example.com')->first()->privacy_accepted_at);
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

    public function test_organization_registration_does_not_claim_email_verification_is_required(): void
    {
        $response = $this->postJson('/api/v1/auth/register/organization', [
            'name' => 'Org Admin',
            'email' => 'admin2@company.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
            'privacy_accepted' => true,
            'organization_name' => 'Tech Co',
            'organization_type' => 'company',
            'organization_contact_email' => 'info@techco.com',
            'proof_file' => UploadedFile::fake()->image('proof.jpg'),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.requires_verification', false)
            ->assertJsonMissingPath('data.resend_available_at');

        $user = User::where('email', 'admin2@company.com')->first();
        $this->assertSame('active', $user->status);
        $this->assertNotNull($user->email_verified_at);

        Notification::assertNothingSent();
    }

    public function test_registration_fails_duplicate_email(): void
    {
        User::forceCreate([
            'name' => 'Test User',
            'email' => 'duplicate@example.com',
            'password' => Hash::make('password123'),
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'user_type' => 'individual',
            'name' => 'Test User',
            'email' => 'duplicate@example.com',
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
            'email' => 'test@example.com',
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

    public function test_organization_registration_accepts_legacy_unprefixed_field_names(): void
    {
        $file = UploadedFile::fake()->image('proof.jpg');

        $response = $this->postJson('/api/v1/auth/register/organization', [
            'name' => 'Sam Admin',
            'email' => 'sam@acme.example',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
            'privacy_accepted' => true,

            'organization_name' => 'Northwind Traders',
            'organization_type' => 'company',
            'organization_industry' => 'Software & IT Services',
            'organization_contact_email' => 'sam@acme.example',
            'organization_contact_phone' => '+962 6 555 1234',

            'organization_size' => '11 - 50 employees',
            'website' => 'https://northwind.example',
            'description' => 'We build developer tooling for the region.',
            'country' => 'Jordan',
            'city' => 'Amman',
            'address' => '123 Main St',
            'postal_code' => '11183',

            'proof_file' => $file,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('organizations', [
            'name' => 'Northwind Traders',
            'industry' => 'Software & IT Services',
            'description' => 'We build developer tooling for the region.',
            'company_size' => '11 - 50 employees',
            'website' => 'https://northwind.example',
            'country' => 'Jordan',
            'city' => 'Amman',
            'address' => '123 Main St',
            'postal_code' => '11183',
            'contact_email' => 'sam@acme.example',
            'contact_phone' => '+962 6 555 1234',
        ]);
    }

    public function test_the_canonical_organization_field_name_wins_when_both_are_sent(): void
    {
        $file = UploadedFile::fake()->image('proof.jpg');

        $response = $this->postJson('/api/v1/auth/register/organization', [
            'name' => 'Both Names Admin',
            'email' => 'both@acme.example',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
            'privacy_accepted' => true,
            'organization_name' => 'Both Names Co',
            'organization_type' => 'company',
            'organization_contact_email' => 'both@acme.example',

            'organization_description' => 'canonical description',
            'description' => 'legacy description that must be ignored',
            'organization_city' => 'Cairo',
            'city' => 'Amman',

            'proof_file' => $file,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('organizations', [
            'name' => 'Both Names Co',
            'description' => 'canonical description',
            'city' => 'Cairo',
        ]);

        $org = Organization::where('name', 'Both Names Co')->first();
        $this->assertSame('canonical description', $org->description);
        $this->assertSame('Cairo', $org->city);
    }
}
