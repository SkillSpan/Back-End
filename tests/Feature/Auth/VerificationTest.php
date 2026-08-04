<?php

namespace Tests\Feature\Auth;

use App\Models\AccountVerification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
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
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@test.com',
            'password' => 'password123',
            'status' => 'pending',
            'email_verified_at' => null,
        ]);

        AccountVerification::create([
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

        $response = $this->postJson('/api/auth/verify', [
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

        $response = $this->postJson('/api/auth/verify', [
            'email' => 'test@test.com',
            'otp' => '123456',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['otp']);
    }

    public function test_wrong_otp_fails(): void
    {
        $this->createPendingUser();

        $response = $this->postJson('/api/auth/verify', [
            'email' => 'test@test.com',
            'otp' => '999999',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['otp']);
    }

    public function test_login_blocked_before_activation(): void
    {
        $this->createPendingUser();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
    }

    public function test_resend_otp_rate_limiting(): void
    {
        $this->createPendingUser();

        $this->postJson('/api/auth/resend-otp', ['email' => 'test@test.com']);
        $response = $this->postJson('/api/auth/resend-otp', ['email' => 'test@test.com']);

        $response->assertStatus(429);
        $response->assertJsonStructure([
            'data' => ['retry_after'],
        ]);
    }

    public function test_login_allowed_after_activation(): void
    {
        $this->createPendingUser();

        $this->postJson('/api/auth/verify', [
            'email' => 'test@test.com',
            'otp' => '123456',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['user', 'token'],
        ]);
    }
}
