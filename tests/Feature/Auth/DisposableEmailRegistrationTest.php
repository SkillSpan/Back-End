<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Mockery\MockInterface;
use Tests\TestCase;

class DisposableEmailRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const DISPOSABLE_MESSAGE = 'Disposable or temporary email addresses are not allowed.';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);

        config(['services.google.client_id' => 'test-client-id']);
    }

    private function individualPayload(array $overrides = []): array
    {
        return array_merge([
            'user_type' => 'individual',
            'name' => 'Test Learner',
            'email' => 'learner@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ], $overrides);
    }

    private function organizationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Org Admin',
            'email' => 'orgadmin@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
            'privacy_accepted' => true,
            'organization_name' => 'Tech Co',
            'organization_type' => 'company',
            'organization_contact_email' => 'info@example.com',
            'proof_file' => UploadedFile::fake()->image('proof.jpg'),
        ], $overrides);
    }

    private function verifiedPayload(array $overrides = []): array
    {
        return array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-client-id',
            'sub' => 'google-sub-disposable',
            'email' => 'googler@gmail.com',
            'email_verified' => true,
            'name' => 'Google User',
        ], $overrides);
    }

    private function mockVerifiedToken(array $payload): void
    {
        $this->partialMock(AuthService::class, function (MockInterface $mock) use ($payload) {
            $mock->shouldAllowMockingProtectedMethods()
                ->shouldReceive('verifyGoogleIdToken')
                ->andReturn($payload);
        });
    }

    public function test_individual_registration_succeeds_for_a_normal_email(): void
    {
        $this->postJson('/api/v1/auth/register', $this->individualPayload())
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'email' => 'learner@example.com',
            'status' => 'pending',
        ]);
    }

    public function test_individual_registration_accepts_a_disposable_email(): void
    {
        $this->postJson('/api/v1/auth/register', $this->individualPayload([
            'email' => 'someone@mailinator.com',
        ]))
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'email' => 'someone@mailinator.com',
            'status' => 'pending',
        ]);
    }

    public function test_individual_registration_accepts_a_disposable_domain_in_mixed_case(): void
    {
        $this->postJson('/api/v1/auth/register', $this->individualPayload([
            'email' => 'Someone@MailInator.COM',
        ]))
            ->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'someone@mailinator.com']);
    }

    public function test_individual_registration_accepts_a_subdomain_of_a_disposable_domain(): void
    {
        $this->postJson('/api/v1/auth/register', $this->individualPayload([
            'email' => 'someone@inbox.mailinator.com',
        ]))
            ->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'someone@inbox.mailinator.com']);
    }

    public function test_individual_registration_still_rejects_a_malformed_email(): void
    {
        $this->postJson('/api/v1/auth/register', $this->individualPayload([
            'email' => 'not-an-email',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_individual_registration_still_rejects_a_duplicate_email(): void
    {
        User::forceCreate([
            'name' => 'Existing',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/register', $this->individualPayload([
            'email' => 'taken@example.com',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_organization_registration_accepts_a_disposable_email(): void
    {
        $this->postJson('/api/v1/auth/register/organization', $this->organizationPayload([
            'email' => 'admin@guerrillamail.com',
        ]))->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'admin@guerrillamail.com']);
        $this->assertDatabaseHas('organizations', [
            'name' => 'Tech Co',
            'verification_status' => 'pending',
        ]);
    }

    public function test_organization_registration_still_succeeds_for_a_normal_email(): void
    {
        $this->postJson('/api/v1/auth/register/organization', $this->organizationPayload())
            ->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'orgadmin@example.com', 'status' => 'active']);
        $this->assertDatabaseHas('organizations', [
            'name' => 'Tech Co',
            'verification_status' => 'pending',
        ]);
    }

    public function test_organization_registration_reports_only_the_missing_proof_file(): void
    {
        $payload = $this->organizationPayload(['email' => 'admin@mailinator.com']);
        unset($payload['proof_file']);

        $this->postJson('/api/v1/auth/register/organization', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['proof_file'])
            ->assertJsonMissingValidationErrors(['email']);
    }

    public function test_organization_contact_email_is_not_subject_to_the_disposable_check(): void
    {
        $this->postJson('/api/v1/auth/register/organization', $this->organizationPayload([
            'organization_contact_email' => 'contact@mailinator.com',
        ]))
            ->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'orgadmin@example.com']);
    }

    public function test_google_signup_rejects_a_disposable_email(): void
    {
        $this->mockVerifiedToken($this->verifiedPayload(['email' => 'throwaway@mailinator.com']));

        $this->postJson('/api/v1/auth/login/google', [
            'credential' => 'valid-id-token',
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['credential'])
            ->assertJsonPath('errors.credential.0', self::DISPOSABLE_MESSAGE);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_disposable_rejection_uses_the_existing_validation_response_structure(): void
    {
        $this->mockVerifiedToken($this->verifiedPayload(['email' => 'throwaway@yopmail.com']));

        $response = $this->postJson('/api/v1/auth/login/google', [
            'credential' => 'valid-id-token',
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['credential']])
            ->assertJsonPath('message', self::DISPOSABLE_MESSAGE)
            ->assertJsonPath('errors.credential.0', self::DISPOSABLE_MESSAGE);

        $this->assertIsArray($response->json('errors.credential'));
    }

    public function test_google_signup_still_creates_an_account_for_a_normal_email(): void
    {
        $this->mockVerifiedToken($this->verifiedPayload());

        $this->postJson('/api/v1/auth/login/google', [
            'credential' => 'valid-id-token',
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.is_new_user', true)
            ->assertJsonPath('data.user.email', 'googler@gmail.com');

        $this->assertDatabaseHas('users', ['email' => 'googler@gmail.com', 'status' => 'active']);
    }

    public function test_google_login_for_an_existing_account_on_a_disposable_domain_is_not_blocked(): void
    {
        User::forceCreate([
            'name' => 'Legacy Learner',
            'email' => 'legacy@mailinator.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->mockVerifiedToken($this->verifiedPayload(['email' => 'legacy@mailinator.com']));

        $this->postJson('/api/v1/auth/login/google', ['credential' => 'valid-id-token'])
            ->assertStatus(200)
            ->assertJsonPath('data.is_new_user', false)
            ->assertJsonPath('data.user.email', 'legacy@mailinator.com');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_the_indisposable_rule_normalises_domain_case_on_its_own(): void
    {
        $this->assertTrue(
            Validator::make(['email' => 'Someone@MAILINATOR.COM'], ['email' => ['indisposable']])->fails(),
            'The rule must reject a disposable domain regardless of letter case.'
        );

        $this->assertFalse(
            Validator::make(['email' => 'someone@gmail.com'], ['email' => ['indisposable']])->fails(),
            'A normal email must not be rejected.'
        );
    }
}
