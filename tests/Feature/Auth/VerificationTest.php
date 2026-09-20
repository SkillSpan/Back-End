<?php

namespace Tests\Feature\Auth;

use App\Models\AccountVerification;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AccountVerificationNotification;
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

    /**
     * The cooldown is still enforced (only one code per window), but the
     * RESPONSE is now the same neutral 200 as every other outcome.
     *
     * The previous 429 was itself an enumeration signal: the limiter key was
     * the resolved user id, so a 429 could only ever be produced for a
     * registered address — an unknown email could never trigger it.
     */
    public function test_resend_otp_rate_limiting_is_neutral_and_sends_only_once(): void
    {
        $this->createPendingUser();

        $this->postJson('/api/v1/auth/resend-otp', ['email' => 'test@test.com'])
            ->assertStatus(200);

        $response = $this->postJson('/api/v1/auth/resend-otp', ['email' => 'test@test.com']);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['resend_available_at']]);

        // The cooldown still works — only the first request issued a code.
        Notification::assertSentTimes(AccountVerificationNotification::class, 1);
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

    /**
     * The whole point of the neutral response: a registered and an
     * unregistered address must be indistinguishable on resend, both on the
     * first request and inside the cooldown window.
     */
    public function test_resend_otp_response_is_identical_for_known_and_unknown_emails(): void
    {
        $this->createPendingUser();

        $known = $this->postJson('/api/v1/auth/resend-otp', ['email' => 'test@test.com']);
        $unknown = $this->postJson('/api/v1/auth/resend-otp', ['email' => 'ghost@example.com']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('success'), $unknown->json('success'));
        $this->assertSame($known->json('message'), $unknown->json('message'));

        // Both expose the same cooldown field — no presence/absence signal.
        $this->assertNotNull($known->json('data.resend_available_at'));
        $this->assertNotNull($unknown->json('data.resend_available_at'));
    }

    public function test_resend_otp_cooldown_is_indistinguishable_for_known_and_unknown_emails(): void
    {
        $this->createPendingUser();

        // Burn the cooldown for both addresses first.
        $this->postJson('/api/v1/auth/resend-otp', ['email' => 'test@test.com']);
        $this->postJson('/api/v1/auth/resend-otp', ['email' => 'ghost@example.com']);

        $knownCooldown = $this->postJson('/api/v1/auth/resend-otp', ['email' => 'test@test.com']);
        $unknownCooldown = $this->postJson('/api/v1/auth/resend-otp', ['email' => 'ghost@example.com']);

        // The unknown address is rate-limited too, so neither the status code
        // nor the body reveals whether the account exists.
        $this->assertSame(200, $knownCooldown->status());
        $this->assertSame($knownCooldown->status(), $unknownCooldown->status());
        $this->assertSame($knownCooldown->json('success'), $unknownCooldown->json('success'));
        $this->assertSame($knownCooldown->json('message'), $unknownCooldown->json('message'));
    }
}
