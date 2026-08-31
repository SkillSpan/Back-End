<?php

namespace Tests\Feature\Auth;

use App\Models\AccountVerification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class VerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    private function createPendingUser(): User
    {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@test.com',
            'password' => 'password123',
            'status' => 'pending',
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

    public function test_valid_otp_activates_account(): void
    {
        $this->createPendingUser();

        $response = $this->postJson('/api/v1/auth/verify', [
            'email' => 'test@test.com',
            'otp' => '123456',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'email' => 'test@test.com',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('account_verifications', [
            'decision' => 'approved',
        ]);
    }

    public function test_expired_otp_fails(): void
    {
        $this->createPendingUser();
        AccountVerification::where('user_id', User::where('email', 'test@test.com')->first()->id)
            ->update(['expires_at' => now()->subMinutes(1)]);

        $response = $this->postJson('/api/v1/auth/verify', [
            'email' => 'test@test.com',
            'otp' => '123456',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['otp']);
    }

    public function test_wrong_otp_fails(): void
    {
        $this->createPendingUser();

        $response = $this->postJson('/api/v1/auth/verify', [
            'email' => 'test@test.com',
            'otp' => '999999',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['otp']);
    }

    public function test_login_blocked_before_activation(): void
    {
        $this->createPendingUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
    }

    public function test_resend_otp_rate_limiting(): void
    {
        $this->createPendingUser();

        $this->postJson('/api/v1/auth/resend-otp', ['email' => 'test@test.com']);
        $response = $this->postJson('/api/v1/auth/resend-otp', ['email' => 'test@test.com']);

        $response->assertStatus(429);
        $response->assertJsonStructure([
            'data' => ['retry_after'],
        ]);
    }

    public function test_login_allowed_after_activation(): void
    {
        $this->createPendingUser();

        $this->postJson('/api/v1/auth/verify', [
            'email' => 'test@test.com',
            'otp' => '123456',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['user', 'token'],
        ]);
    }

    /**
     * Anti-enumeration: an unknown address must receive the same neutral
     * success shape as a known one on resend, and the same generic
     * "invalid code" failure as a wrong OTP on verify — with NO
     * validation error pointing at the email field itself.
     */
    public function test_resend_otp_returns_neutral_success_for_unknown_email(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/resend-otp', [
            'email' => 'ghost@example.com',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'If an account with that email exists, a new verification code has been sent.');

        Notification::assertNothingSent();
    }

    public function test_verify_unknown_email_is_indistinguishable_from_wrong_otp(): void
    {
        $unknown = $this->postJson('/api/v1/auth/verify', [
            'email' => 'ghost@example.com',
            'otp' => '123456',
        ])->assertStatus(422);

        $this->createPendingUser();
        $wrongOtp = $this->postJson('/api/v1/auth/verify', [
            'email' => 'test@test.com',
            'otp' => '999999',
        ])->assertStatus(422);

        // Same failing field, no email-specific leak in either case.
        $unknown->assertJsonValidationErrors(['otp']);
        $unknown->assertJsonMissingValidationErrors(['email']);
        $wrongOtp->assertJsonValidationErrors(['otp']);
        $this->assertSame(
            $unknown->json('message'),
            $wrongOtp->json('message'),
        );
    }
}
