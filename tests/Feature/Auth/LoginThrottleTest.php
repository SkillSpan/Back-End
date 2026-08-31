<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Login brute-force lockout contract: after 5 failed attempts the account
 * (email|ip key) is locked for 300s. The lock must be distinguishable
 * from a validation failure — HTTP 429 + Retry-After header — while the
 * JSON body keeps the familiar errors.email shape for existing frontends.
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    private function createActiveLearner(): User
    {
        $user = User::forceCreate([
            'name' => 'Learner One',
            'email' => 'lock@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);

        return $user;
    }

    public function test_lockout_returns_429_with_retry_after_header(): void
    {
        $this->createActiveLearner();

        // Burn through the allowance with wrong passwords.
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'lock@test.com',
                'password' => "wrong-{$i}",
            ])->assertStatus(422);
        }

        // Even the CORRECT password is now rejected — but as a rate-limit
        // response, not a validation error.
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'lock@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(429);
        $this->assertNotEmpty($response->headers->get('Retry-After'));
        $this->assertStringContainsString(
            'Too many login attempts',
            (string) $response->json('message')
        );
        $this->assertStringContainsString(
            'Too many login attempts',
            (string) $response->json('errors.email.0')
        );

        // The lock is keyed per email: nobody else is affected.
        $other = User::forceCreate([
            'name' => 'Other User',
            'email' => 'other-lock@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $other->roles()->attach(Role::where('slug', 'learner')->first()->id);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'other-lock@test.com',
            'password' => 'password123',
        ])->assertStatus(200);
    }
}
