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

/**
 * The registration fixtures deliberately use example.com, NOT test.com.
 *
 * test.com is on the disposable-domains blocklist that
 * propaganistas/laravel-disposable-email ships, so any address at that domain
 * is now rejected with 422 by the 'indisposable' rule on both self-registration
 * FormRequests. Using it here made these tests pass for the wrong reason —
 * e.g. the duplicate-email test still went green because the address was
 * rejected as disposable before the unique rule was ever reached.
 *
 * Disposable-email behaviour itself is covered separately in
 * DisposableEmailRegistrationTest.
 */
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
            // Academic fields (university/specialization) are collected
            // later via POST /api/v1/profile as Foreign Keys — the
            // registration payload only seeds a bare profile row.
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
            // SRS PROF-05: an untouched profile has zero completeness —
            // no hardcoded baseline outranking real progress.
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

    /**
     * The organization flow does not send an OTP — the account is marked
     * email-verified at registration and gated on the manual proof-document
     * review. The response must therefore NOT claim that email verification
     * is required, and must not hand back a resend window for a code that
     * was never issued (which is what used to send clients into a dead-end
     * verification screen).
     */
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

        // The account really is verified and active at this point, so the
        // response and the persisted state now agree.
        $user = User::where('email', 'admin2@company.com')->first();
        $this->assertSame('active', $user->status);
        $this->assertNotNull($user->email_verified_at);

        // And no verification code was ever sent.
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
}
