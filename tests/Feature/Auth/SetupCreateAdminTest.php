<?php

namespace Tests\Feature\Auth;

use App\Models\AuditEvent;
use App\Models\AuthSession;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue($admin->hasRole('admin'));

        $token = $response->json('data.token');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

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

        $this->postJson('/api/v1/setup/create-admin', $this->payload(['email' => 'not-an-email']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->postJson('/api/v1/setup/create-admin', $this->payload(['email' => $existing->email]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->postJson('/api/v1/setup/create-admin', $this->payload(['password' => 'short']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->postJson('/api/v1/setup/create-admin', $this->payload(['name' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_route_throttle_blocks_secret_brute_forcing(): void
    {
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/setup/create-admin', $this->payload(['secret' => "guess-{$i}"]))
                ->assertStatus(403);
        }

        $this->postJson('/api/v1/setup/create-admin', $this->payload())
            ->assertStatus(429);

        $this->assertSame(0, AuthSession::count());
    }

    public function test_is_disabled_by_the_kill_switch_even_with_the_correct_secret(): void
    {
        config(['services.admin_setup.enabled' => false]);

        $this->postJson('/api/v1/setup/create-admin', $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'disabled'));

        $this->assertSame(0, User::count());
    }

    public function test_a_successful_bootstrap_is_audited(): void
    {
        $response = $this->postJson('/api/v1/setup/create-admin', $this->payload())
            ->assertStatus(201);

        $admin = User::where('email', $response->json('data.email'))->firstOrFail();

        $this->assertDatabaseHas('audit_events', [
            'actor_id' => null,
            'action' => 'setup.admin_created',
            'entity_type' => User::class,
            'entity_id' => $admin->id,
            'purpose' => 'shared_secret_bootstrap',
        ]);

        $event = AuditEvent::where('action', 'setup.admin_created')->firstOrFail();
        $this->assertSame('admin', $event->after['role']);
    }

    public function test_a_refused_attempt_writes_no_audit_row(): void
    {
        $this->postJson('/api/v1/setup/create-admin', $this->payload(['secret' => 'wrong-guess']))
            ->assertStatus(403);

        $this->assertSame(0, AuditEvent::count());
    }
}
