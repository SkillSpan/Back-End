<?php

namespace Tests\Feature\Auth;

use App\Models\AccountVerification;
use App\Models\Role;
use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

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

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['otp']);

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

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

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

        $this->assertSame('active', $user->fresh()->status);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

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

        $this->assertSame(1, $user->tokens()->count());
    }

    private function seedPasswordResetToken(User $user, string $otp): void
    {
        DB::table('password_reset_tokens')->insert([
            'user_id' => $user->id,
            'token_hash' => Hash::make($otp),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => null,
            'created_at' => now(),
        ]);
    }

    public function test_a_suspended_account_cannot_request_a_password_reset(): void
    {
        $this->userWithStatus('suspended');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'test@test.com'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_a_suspended_account_cannot_complete_a_password_reset(): void
    {
        $user = $this->userWithStatus('suspended');
        $this->seedPasswordResetToken($user, '123456');

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'test@test.com',
            'otp' => '123456',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422)->assertJsonValidationErrors(['otp']);

        $this->assertTrue(Hash::check('password123', $user->fresh()->password));
        $this->assertFalse(Hash::check('brand-new-password', $user->fresh()->password));
        $this->assertNull(
            DB::table('password_reset_tokens')->where('user_id', $user->id)->value('consumed_at')
        );
    }

    public function test_a_suspended_account_cannot_verify_a_reset_code(): void
    {
        $user = $this->userWithStatus('suspended');
        $this->seedPasswordResetToken($user, '123456');

        $this->postJson('/api/v1/auth/forgot-password/verify', [
            'email' => 'test@test.com',
            'otp' => '123456',
        ])->assertStatus(422)->assertJsonValidationErrors(['otp']);
    }

    public function test_a_deleted_account_cannot_complete_a_password_reset(): void
    {
        $user = $this->userWithStatus('deleted');
        $this->seedPasswordResetToken($user, '123456');

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'test@test.com',
            'otp' => '123456',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('password123', $user->fresh()->password));
    }

    public function test_a_pending_account_can_still_start_a_password_reset(): void
    {
        $user = $this->userWithStatus('pending');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'test@test.com'])
            ->assertOk();

        Notification::assertSentTo($user, PasswordResetNotification::class);
        $this->assertSame(1, DB::table('password_reset_tokens')->count());
    }
}
