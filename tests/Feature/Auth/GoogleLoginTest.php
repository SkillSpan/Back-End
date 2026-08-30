<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * POST /api/v1/auth/login/google — input contract, token-verification
 * failure handling, and the existing/new account flows.
 *
 * verifyGoogleIdToken() is the network boundary (Google's public JWKS).
 * Flow tests partial-mock the service and stub exactly that method with
 * realistic verified payloads; the malformed-token test uses the REAL
 * method because a token without JWT segments fails before any network
 * call — the same failure path an unknown-kid or expired token takes.
 * A real Google-issued ID token is exercised manually via Postman.
 */
class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    /**
     * A payload shaped like a verified Google ID token (iss/aud/sub/email).
     */
    private function verifiedPayload(array $overrides = []): array
    {
        return array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-client-id',
            'sub' => 'google-sub-123',
            'email' => 'learner@gmail.com',
            'email_verified' => true,
            'name' => 'Google Learner',
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

    private function createActiveUser(string $email): User
    {
        return User::create([
            'name' => 'Existing Learner',
            'email' => $email,
            'password' => 'password',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    public function test_credential_is_required(): void
    {
        $this->postJson('/api/v1/auth/login/google', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['credential']);
    }

    public function test_malformed_credential_fails_as_422_not_500(): void
    {
        config(['services.google.client_id' => 'test-client-id']);

        // Real service, real Google_Client: 'garbage-token' has no JWT
        // segments, so the JWT library throws before any network call.
        // Without the try/catch in verifyGoogleIdToken() this surfaced
        // as a 500 with a stack trace.
        $this->postJson('/api/v1/auth/login/google', ['credential' => 'garbage-token'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['credential']);
    }

    public function test_fails_cleanly_when_google_is_not_configured(): void
    {
        config(['services.google.client_id' => null]);

        $this->postJson('/api/v1/auth/login/google', [
            'credential' => 'some-id-token',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['credential'])
            ->assertJsonPath('errors.credential.0', fn ($message) => str_contains($message, 'not configured'));
    }

    public function test_existing_email_account_logs_in_links_google_and_needs_no_consents(): void
    {
        $user = $this->createActiveUser('learner@gmail.com');

        $this->mockVerifiedToken($this->verifiedPayload([
            'email' => $user->email,
            'sub' => 'google-sub-123',
        ]));

        // No terms/privacy flags: an existing account must not re-consent.
        $this->postJson('/api/v1/auth/login/google', ['credential' => 'valid-id-token'])
            ->assertStatus(200)
            ->assertJsonPath('data.is_new_user', false)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['user', 'token', 'token_type', 'is_new_user']]);

        $user->refresh();

        $this->assertSame('google-sub-123', $user->google_id);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('auth_sessions', [
            'user_id' => $user->id,
            'result' => 'success',
        ]);
    }

    public function test_repeated_google_login_does_not_duplicate_users(): void
    {
        $user = $this->createActiveUser('learner@gmail.com');

        $this->mockVerifiedToken($this->verifiedPayload([
            'email' => $user->email,
            'sub' => 'google-sub-123',
        ]));

        foreach ([1, 2] as $attempt) {
            $this->postJson('/api/v1/auth/login/google', ['credential' => 'valid-id-token'])
                ->assertStatus(200)
                ->assertJsonPath('data.is_new_user', false);
        }

        $user->refresh();

        $this->assertSame('google-sub-123', $user->google_id);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_new_google_user_without_consents_is_rejected_before_creation(): void
    {
        $this->mockVerifiedToken($this->verifiedPayload());

        $this->postJson('/api/v1/auth/login/google', ['credential' => 'valid-id-token'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['terms_accepted', 'privacy_accepted']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_new_google_user_is_created_with_google_id_role_and_profile(): void
    {
        $this->mockVerifiedToken($this->verifiedPayload());

        $this->postJson('/api/v1/auth/login/google', [
            'credential' => 'valid-id-token',
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ])->assertStatus(200)
            ->assertJsonPath('data.is_new_user', true)
            ->assertJsonPath('data.user.email', 'learner@gmail.com')
            ->assertJsonPath('data.token_type', 'Bearer');

        $user = User::where('email', 'learner@gmail.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('google-sub-123', $user->google_id);
        $this->assertSame('active', $user->status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->hasRole('learner'));
        $this->assertNotNull($user->studentProfile);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('auth_sessions', [
            'user_id' => $user->id,
            'result' => 'success',
        ]);
    }

    public function test_unverified_google_email_is_rejected(): void
    {
        $this->mockVerifiedToken($this->verifiedPayload(['email_verified' => false]));

        $this->postJson('/api/v1/auth/login/google', ['credential' => 'valid-id-token'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['credential']);

        $this->assertDatabaseCount('users', 0);
    }
}
