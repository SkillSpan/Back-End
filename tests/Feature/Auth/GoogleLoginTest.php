<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

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
        return User::forceCreate([
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

    public function test_pending_organization_google_login_leaves_no_open_transaction(): void
    {
        $user = $this->createActiveUser('orgadmin@gmail.com');

        $organization = Organization::forceCreate([
            'name' => 'Pending Org',
            'type' => 'company',
            'verification_status' => 'pending',
            'contact_email' => 'contact@pending.com',
        ]);

        OrganizationMember::forceCreate([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_in_org' => 'admin',
            'status' => 'active',
        ]);

        $this->mockVerifiedToken($this->verifiedPayload(['email' => 'orgadmin@gmail.com']));

        $levelBefore = DB::transactionLevel();

        $this->postJson('/api/v1/auth/login/google', ['credential' => 'valid-id-token'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['credential']);

        $this->assertSame($levelBefore, DB::transactionLevel());

        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseCount('auth_sessions', 0);
    }
}
