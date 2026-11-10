<?php

namespace Tests\Feature\Auth;

use App\Models\AuthSession;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'password123';

    private const EMAIL = 'learner@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    private function createActiveLearner(): User
    {
        $user = User::forceCreate([
            'name' => 'Learner One',
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $role = Role::where('slug', 'learner')->first();
        $user->roles()->attach($role->id, ['organization_id' => null]);

        return $user;
    }

    private function login(): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ])->assertOk()->json('data.token');
    }

    public function test_login_writes_an_auditable_auth_sessions_row(): void
    {
        $user = $this->createActiveLearner();
        $this->login();

        $this->assertDatabaseHas('auth_sessions', [
            'user_id' => $user->id,
            'revoked_at' => null,
        ]);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = $this->createActiveLearner();
        $tokenA = $this->login();
        $tokenB = $this->login();

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(
            1,
            AuthSession::where('user_id', $user->id)->whereNotNull('revoked_at')->count()
        );
        $this->assertSame(
            1,
            AuthSession::where('user_id', $user->id)->whereNull('revoked_at')->count()
        );

        $this->app->forgetInstance('auth');

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson('/api/v1/profile')
            ->assertStatus(401);

        $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->getJson('/api/v1/profile')
            ->assertStatus(404);
    }

    public function test_logout_all_revokes_every_session_including_the_requesting_device(): void
    {
        $user = $this->createActiveLearner();
        $tokenA = $this->login();
        $tokenB = $this->login();

        $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->postJson('/api/v1/auth/logout-all')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.redirect_url', '/login');

        $this->assertSame(
            2,
            AuthSession::where('user_id', $user->id)->whereNotNull('revoked_at')->count()
        );
        $this->assertSame(
            0,
            AuthSession::where('user_id', $user->id)->whereNull('revoked_at')->count()
        );

        $this->app->forgetInstance('auth');

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson('/api/v1/profile')
            ->assertStatus(401);

        $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->getJson('/api/v1/profile')
            ->assertStatus(401);
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertStatus(401);
        $this->postJson('/api/v1/auth/logout-all')->assertStatus(401);
    }

    public function test_naturally_expired_token_cannot_access_protected_endpoints(): void
    {
        config(['sanctum.expiration' => 60]);

        $user = $this->createActiveLearner();
        $token = $this->login();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/profile')
            ->assertStatus(404);

        $this->travel(61)->minutes();

        $this->app->forgetInstance('auth');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/profile')
            ->assertStatus(401);
    }
}
