<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        return User::forceCreate([
            'name' => 'Test User',
            'email' => self::EMAIL,
            'password' => $password,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

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

    public function test_resend_sends_a_fresh_code_once_the_cooldown_passes(): void
    {
        $user = $this->createActiveUser();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => self::EMAIL])
            ->assertOk();
        Notification::assertSentTo($user, PasswordResetNotification::class);

        $this->postJson('/api/v1/auth/forgot-password/resend', ['email' => self::EMAIL])
            ->assertOk();
        Notification::assertSentToTimes($user, PasswordResetNotification::class, 1);

        $this->travel(61)->seconds();

        $this->postJson('/api/v1/auth/forgot-password/resend', ['email' => self::EMAIL])
            ->assertOk();
        Notification::assertSentToTimes($user, PasswordResetNotification::class, 2);
    }

    public function test_resend_is_neutral_for_unknown_emails(): void
    {
        $known = $this->createActiveUser();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => self::EMAIL])->assertOk();

        $knownBody = $this->postJson('/api/v1/auth/forgot-password/resend', ['email' => self::EMAIL])->assertOk()->json();
        $unknownBody = $this->postJson('/api/v1/auth/forgot-password/resend', ['email' => 'ghost@nowhere.io'])->assertOk()->json();

        unset($knownBody['data']['resend_available_at'], $unknownBody['data']['resend_available_at']);
        $this->assertSame($knownBody, $unknownBody);
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

        $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL,
            'password' => 'oldpassword123',
        ])->assertStatus(422);

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

    public function test_a_reset_code_cannot_be_used_twice(): void
    {
        $user = $this->createActiveUser('oldpassword123');
        $otp = $this->requestPasswordResetAndGetOtp($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => self::EMAIL,
            'otp' => $otp,
            'password' => 'firstnew456',
            'password_confirmation' => 'firstnew456',
        ])->assertOk()->assertJsonPath('success', true);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => self::EMAIL,
            'otp' => $otp,
            'password' => 'secondnew789',
            'password_confirmation' => 'secondnew789',
        ])->assertStatus(422)->assertJsonValidationErrors(['otp']);

        $this->assertTrue(Hash::check('firstnew456', $user->fresh()->password));
        $this->assertFalse(Hash::check('secondnew789', $user->fresh()->password));

        $this->assertSame(1, DB::table('password_reset_tokens')
            ->where('user_id', $user->id)
            ->whereNotNull('consumed_at')
            ->count());
    }

    public function test_the_consume_step_is_a_conditional_update(): void
    {
        $user = $this->createActiveUser();
        $this->requestPasswordResetAndGetOtp($user);

        $id = DB::table('password_reset_tokens')->where('user_id', $user->id)->value('id');

        $first = DB::table('password_reset_tokens')
            ->where('id', $id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $second = DB::table('password_reset_tokens')
            ->where('id', $id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
    }

    public function test_a_token_claimed_by_a_competing_request_cannot_be_used(): void
    {
        $user = $this->createActiveUser('oldpassword123');
        $otp = $this->requestPasswordResetAndGetOtp($user);

        $tokenId = DB::table('password_reset_tokens')
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->value('id');

        $this->assertNotNull($tokenId);

        $competingClaim = false;

        DB::listen(function ($query) use ($tokenId, &$competingClaim): void {
            if ($competingClaim || ! str_contains($query->sql, 'password_reset_tokens')) {
                return;
            }

            if (! str_starts_with(ltrim(strtolower($query->sql)), 'select')) {
                return;
            }

            $competingClaim = true;

            DB::table('password_reset_tokens')
                ->where('id', $tokenId)
                ->update(['consumed_at' => now()]);
        });

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => self::EMAIL,
            'otp' => $otp,
            'password' => 'brandnew789',
            'password_confirmation' => 'brandnew789',
        ]);

        $this->assertTrue(
            $competingClaim,
            'the competing claim never fired — the race was not reproduced'
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['otp']);

        $this->assertTrue(Hash::check('oldpassword123', $user->fresh()->password));
        $this->assertFalse(Hash::check('brandnew789', $user->fresh()->password));
    }
}
