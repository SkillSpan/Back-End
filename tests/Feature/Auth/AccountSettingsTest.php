<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

    private function user(array $attributes = []): User
    {
        return User::forceCreate(array_merge([
            'name' => 'Representative',
            'email' => uniqid('rep-').'@example.com',
            'password' => 'password123',
            'phone' => '0999999999',
            'status' => 'active',
            'email_verified_at' => now(),
        ], $attributes));
    }

    public function test_account_settings_endpoints_require_authentication(): void
    {
        $this->patchJson('/api/v1/auth/me', ['name' => 'Someone'])->assertStatus(401);
        $this->postJson('/api/v1/auth/change-password', [])->assertStatus(401);
    }

    public function test_patch_me_updates_the_name_and_the_phone(): void
    {
        $user = $this->user();

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/me', [
            'name' => 'New Representative',
            'phone' => '0911111111',
        ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.name', 'New Representative')
            ->assertJsonPath('data.user.phone', '0911111111');

        $fresh = $user->fresh();

        $this->assertSame('New Representative', $fresh->name);
        $this->assertSame('0911111111', $fresh->phone);
    }

    public function test_patch_me_leaves_omitted_fields_untouched(): void
    {
        $user = $this->user();

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/me', ['name' => 'Only The Name'])
            ->assertStatus(200);

        $fresh = $user->fresh();

        $this->assertSame('Only The Name', $fresh->name);
        $this->assertSame('0999999999', $fresh->phone);
    }

    public function test_patch_me_validates_the_name_and_the_phone(): void
    {
        Sanctum::actingAs($this->user());

        $this->patchJson('/api/v1/auth/me', ['name' => 'A'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->patchJson('/api/v1/auth/me', ['phone' => str_repeat('9', 21)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_change_password_updates_the_hash_and_keeps_the_current_token(): void
    {
        $user = $this->user();

        $current = $user->createToken('current');
        $other = $user->createToken('other');

        $this->withToken($current->plainTextToken)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'password123',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.revoked_sessions', 1);

        $this->assertTrue(Hash::check('new-password-456', (string) $user->fresh()->password));

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
    }

    public function test_change_password_rejects_a_wrong_current_password(): void
    {
        Sanctum::actingAs($this->user());

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'not-my-password',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_change_password_rejects_reusing_the_current_password(): void
    {
        Sanctum::actingAs($this->user());

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password123',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_change_password_requires_confirmation_and_eight_characters(): void
    {
        Sanctum::actingAs($this->user());

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password123',
            'password' => 'new-password-456',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password123',
            'password' => 'short7x',
            'password_confirmation' => 'short7x',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }
}
