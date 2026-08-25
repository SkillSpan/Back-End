<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'reset@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function createActiveUser(string $password = 'password123'): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => self::EMAIL,
            'password' => $password,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * The OTP lives in a protected promoted property, so we pull it back
     * out through the mail payload the notification builds.
     */
    private function requestPasswordResetAndGetOtp(User $user): string
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => self::EMAIL])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $otp = null;

        Notification::assertSentTo(
            $user,
            PasswordResetNotification::class,
            function (PasswordResetNotification $notification, array $channels, object $notifiable) use (&$otp): bool {
                $otp = $notification->toMail($notifiable)->viewData['otp'];

                return true;
            }
        );

        return $otp;
    }

    public function test_forgot_password_unknown_email_returns_neutral_success_without_sending(): void
    {
        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'ghost@nowhere.com',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertStringContainsString(
            'If an account with that email exists',
            $response->json('message')
        );
        Notification::assertNothingSent();
    }

    public function test_forgot_password_known_email_sends_reset_notification(): void
    {
        $user = $this->createActiveUser();

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => self::EMAIL,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        Notification::assertSentTo($user, PasswordResetNotification::class);
        $this->assertDatabaseHas('password_reset_tokens', [
            'user_id' => $user->id,
            'consumed_at' => null,
        ]);
    }

    public function test_resend_within_cooldown_stays_neutral_and_does_not_send_twice(): void
    {
        $user = $this->createActiveUser();

        $first = $this->postJson('/api/v1/auth/forgot-password', ['email' => self::EMAIL]);
        $first->assertStatus(200)->assertJsonPath('success', true);

        // Both the repeat forgot-password call and the dedicated resend
        // endpoint must stay inside the cooldown window: same neutral 200.
        $repeat = $this->postJson('/api/v1/auth/forgot-password', ['email' => self::EMAIL]);
        $repeat->assertStatus(200)->assertJsonPath('success', true);

        $resend = $this->postJson('/api/v1/auth/forgot-password/resend', ['email' => self::EMAIL]);
        $resend->assertStatus(200)->assertJsonPath('success', true);
        $this->assertStringContainsString(
            'If an account with that email exists',
            $resend->json('message')
        );

        Notification::assertSentTo($user, PasswordResetNotification::class, 1);
    }

    public function test_verify_with_correct_otp_succeeds(): void
    {
        $user = $this->createActiveUser();
        $otp = $this->requestPasswordResetAndGetOtp($user);

        $response = $this->postJson('/api/v1/auth/forgot-password/verify', [
            'email' => self::EMAIL,
            'otp' => $otp,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    public function test_verify_with_wrong_otp_fails(): void
    {
        $user = $this->createActiveUser();
        $this->requestPasswordResetAndGetOtp($user);

        $response = $this->postJson('/api/v1/auth/forgot-password/verify', [
            'email' => self::EMAIL,
            'otp' => '000000',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['otp']);
    }

    public function test_full_flow_verify_does_not_consume_code_then_reset_and_login(): void
    {
        $user = $this->createActiveUser('oldpassword123');
        $otp = $this->requestPasswordResetAndGetOtp($user);

        // Pre-check on the frontend screen: verifying the code must NOT burn it.
        $this->postJson('/api/v1/auth/forgot-password/verify', [
            'email' => self::EMAIL,
            'otp' => $otp,
        ])->assertStatus(200);

        $record = DB::table('password_reset_tokens')
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->first();
        $this->assertNotNull($record);
        $this->assertNull($record->consumed_at, 'forgot-password/verify must not consume the code');

        $reset = $this->postJson('/api/v1/auth/reset-password', [
            'email' => self::EMAIL,
            'otp' => $otp,
            'password' => 'newpassword456',
            'password_confirmation' => 'newpassword456',
        ]);

        $reset->assertStatus(200);
        $reset->assertJsonPath('success', true);
        $this->assertDatabaseMissing('password_reset_tokens', [
            'id' => $record->id,
            'consumed_at' => null,
        ]);

        // Old credentials are dead...
        $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL,
            'password' => 'oldpassword123',
        ])->assertStatus(422);

        // ...and the new password logs in with a token.
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL,
            'password' => 'newpassword456',
        ]);

        $login->assertStatus(200);
        $login->assertJsonPath('success', true);
        $this->assertNotEmpty($login->json('data.token'));
    }

    public function test_expired_otp_cannot_reset_password(): void
    {
        $user = $this->createActiveUser();
        $otp = $this->requestPasswordResetAndGetOtp($user);

        DB::table('password_reset_tokens')
            ->where('user_id', $user->id)
            ->update(['expires_at' => now()->subMinute()]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => self::EMAIL,
            'otp' => $otp,
            'password' => 'newpassword456',
            'password_confirmation' => 'newpassword456',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['otp']);
    }

    public function test_attempts_cap_blocks_even_the_correct_otp(): void
    {
        $user = $this->createActiveUser();
        $otp = $this->requestPasswordResetAndGetOtp($user);

        DB::table('password_reset_tokens')
            ->where('user_id', $user->id)
            ->update(['attempts' => config('verification.max_attempts')]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => self::EMAIL,
            'otp' => $otp,
            'password' => 'newpassword456',
            'password_confirmation' => 'newpassword456',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['otp']);
    }
}
