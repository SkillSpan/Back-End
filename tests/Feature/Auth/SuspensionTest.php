<?php

namespace Tests\Feature\Auth;

use App\Models\AccountVerification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Regression tests for the suspension bypass via the email-verification flow.
 *
 * Previously verifyOtp() unconditionally force-wrote status = 'active' on a
 * successful OTP, and neither resendOtp() nor AuthController::resendOtp()
 * looked at account status. A suspended user who still controlled their
 * mailbox could therefore request a fresh code and clear their own
 * suspension through a public, unauthenticated endpoint.
 */
class SuspensionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    private function userWithStatus(string $status): User
    {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@test.com',
            'password' => 'password123',
            'status' => $status,
            'email_verified_at' => null,
        ]);

        AccountVerification::forceCreate([
            'user_id' => $user->id,
            'organization_id' => null,
            'channel' => 'email',
            'challenge_state' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
            'decision' => 'pending',
        ]);

        return $user;
    }

    public function test_suspended_user_cannot_verify_otp(): void
    {
        $user = $this->userWithStatus('suspended');

        $response = $this->postJson('/api/v1/auth/verify', [
            'email' => 'test@test.com',
            'otp' => '123456',
        ]);

        // Same failure shape as a wrong/expired code — no state disclosure.
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['otp']);

        // The suspension must survive the attempt.
        $this->assertSame('suspended', $user->fresh()->status);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_suspended_user_cannot_reactivate_through_verification(): void
    {
        $user = $this->userWithStatus('suspended');

        $this->postJson('/api/v1/auth/verify', [
            'email' => 'test@test.com',
            'otp' => '123456',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 'suspended',
        ]);
        $this->assertDatabaseMissing('users', [
            'id' => $user->id,
            'status' => 'active',
        ]);
    }

    public function test_suspended_users_challenge_is_not_consumed(): void
    {
        $this->userWithStatus('suspended');

        $this->postJson('/api/v1/auth/verify', [
            'email' => 'test@test.com',
            'otp' => '123456',
        ]);

        // The challenge is left pending — it was never evaluated, so a later
        // legitimate verification (after reinstatement) is not broken.
        $this->assertDatabaseHas('account_verifications', [
            'decision' => 'pending',
        ]);
    }

    public function test_suspended_user_cannot_resend_otp(): void
    {
        $this->userWithStatus('suspended');

        $before = AccountVerification::count();

        $response = $this->postJson('/api/v1/auth/resend-otp', [
            'email' => 'test@test.com',
        ]);

        // Neutral success — indistinguishable from an unknown address.
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        // Crucially: no new challenge was issued and no mail was sent.
        $this->assertSame($before, AccountVerification::count());
        Notification::assertNothingSent();
    }

    public function test_deleted_user_cannot_verify_otp(): void
    {
        $user = $this->userWithStatus('deleted');

        $this->postJson('/api/v1/auth/verify', [
            'email' => 'test@test.com',
            'otp' => '123456',
        ])->assertStatus(422);

        $this->assertSame('deleted', $user->fresh()->status);
    }

    public function test_pending_user_still_activates_normally(): void
    {
        $user = $this->userWithStatus('pending');

        $this->postJson('/api/v1/auth/verify', [
            'email' => 'test@test.com',
            'otp' => '123456',
        ])->assertStatus(200);

        $this->assertSame('active', $user->fresh()->status);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_active_user_verification_does_not_change_status(): void
    {
        $user = $this->userWithStatus('active');

        $this->postJson('/api/v1/auth/verify', [
            'email' => 'test@test.com',
            'otp' => '123456',
        ])->assertStatus(200);

        // Still active, and email_verified_at gets stamped.
        $this->assertSame('active', $user->fresh()->status);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    /*
    |--------------------------------------------------------------------------
    | Suspension enforcement on already-issued Sanctum tokens
    |--------------------------------------------------------------------------
    |
    | Suspension previously only blocked the next login. A token minted
    | before the suspension stayed valid for its full lifetime, because
    | neither Sanctum nor the role middlewares look at `status`.
    */

    public function test_suspended_user_with_a_valid_token_is_rejected(): void
    {
        $user = $this->userWithStatus('suspended');
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/skills/matrix')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_DISABLED');
    }

    public function test_suspended_users_tokens_are_revoked_as_a_side_effect(): void
    {
        $user = $this->userWithStatus('suspended');
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->assertSame(1, $user->tokens()->count());

        $this->withToken($token)
            ->getJson('/api/v1/skills/matrix')
            ->assertStatus(403);

        // The session is ended, not just blocked for this one request.
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_suspended_user_is_rejected_on_a_role_protected_route(): void
    {
        $user = $this->userWithStatus('suspended');
        $user->roles()->attach(Role::where('slug', 'learner')->first()->id, ['organization_id' => null]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/profile')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_DISABLED');
    }

    public function test_deleted_status_user_with_a_valid_token_is_rejected(): void
    {
        $user = $this->userWithStatus('deleted');
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/skills/matrix')
            ->assertStatus(403);
    }

    public function test_active_user_with_a_valid_token_is_unaffected(): void
    {
        $user = $this->userWithStatus('active');
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/skills/matrix')
            ->assertStatus(200);

        // An active account keeps its session.
        $this->assertSame(1, $user->tokens()->count());
    }
}
