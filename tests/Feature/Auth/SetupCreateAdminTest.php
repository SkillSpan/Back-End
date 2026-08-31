<?php

namespace Tests\Feature\Auth;

use App\Models\AuthSession;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api/v1/setup/create-admin — the shared-secret bootstrap endpoint.
 * Contract under test: disabled without a configured secret, timing-safe
 * secret comparison, full admin provisioning (active + verified + role +
 * Bearer token), auditable auth_sessions row, input validation and the
 * route-level throttle that makes online secret brute-forcing impractical.
 */
class SetupCreateAdminTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'setup-secret-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.admin_setup.secret' => self::SECRET]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'secret' => self::SECRET,
            'name' => 'New Admin',
            'email' => uniqid().'admin@test.com',
            'password' => 'super-secret-password',
        ], $overrides);
    }

    public function test_is_disabled_when_no_secret_is_configured(): void
    {
        config(['services.admin_setup.secret' => '']);

        $this->postJson('/api/v1/setup/create-admin', $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'disabled'));

        // Nothing was created.
        $this->assertSame(0, User::count());
    }

    public function test_rejects_a_wrong_secret_without_creating_anything(): void
    {
        $this->postJson('/api/v1/setup/create-admin', $this->payload(['secret' => 'wrong-guess']))
            ->assertStatus(403)
            ->assertJsonPath('message', 'Invalid setup secret.');

        $this->assertSame(0, User::count());
    }

    public function test_creates_an_active_verified_admin_with_role_token_and_audit_row(): void
    {
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);

        $response = $this->postJson('/api/v1/setup/create-admin', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer');

        $admin = User::where('email', $response->json('data.email'))->first();
        $this->assertNotNull($admin);
        $this->assertSame('active', $admin->status);
        $this->assertNotNull($admin->email_verified_at); // no OTP round-trip for bootstrap admins
        $this->assertTrue($admin->hasRole('admin'));

        // The issued token is immediately usable on a protected endpoint.
        $token = $response->json('data.token');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // And the login-style session audit row was written for it.
        $this->assertDatabaseHas('auth_sessions', [
            'user_id' => $admin->id,
            'result' => 'success',
        ]);
    }

    public function test_validates_admin_input(): void
    {
        $existing = User::forceCreate([
            'name' => 'Taken Email',
            'email' => 'taken@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        // Bad email format.
        $this->postJson('/api/v1/setup/create-admin', $this->payload(['email' => 'not-an-email']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // Duplicate email.
        $this->postJson('/api/v1/setup/create-admin', $this->payload(['email' => $existing->email]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // Password shorter than 8 chars.
        $this->postJson('/api/v1/setup/create-admin', $this->payload(['password' => 'short']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        // Missing name.
        $this->postJson('/api/v1/setup/create-admin', $this->payload(['name' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_route_throttle_blocks_secret_brute_forcing(): void
    {
        // throttle:5,1 — five requests per minute per IP. Every attempt,
        // even wrong-secret 403s, counts toward the ceiling.
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/setup/create-admin', $this->payload(['secret' => "guess-{$i}"]))
                ->assertStatus(403);
        }

        // The 6th attempt is rate-limited before it even reaches the
        // secret comparison.
        $this->postJson('/api/v1/setup/create-admin', $this->payload())
            ->assertStatus(429);

        $this->assertSame(0, AuthSession::count());
    }
}
