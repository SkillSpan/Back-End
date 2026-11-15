<?php

namespace Tests\Feature\Communication;

use App\Models\NotificationPreference;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    private function user(): User
    {
        $user = User::forceCreate([
            'name' => 'Company Admin',
            'email' => uniqid('toggles-').'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);

        return $user;
    }

    public function test_the_frontend_toggle_keys_map_to_the_real_categories(): void
    {
        $user = $this->user();

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/notifications/preferences', [
            'preferences' => [
                'new_applicant' => false,
                'messages' => false,
                'project_status' => true,
            ],
        ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.preferences.new_applicant', false)
            ->assertJsonPath('data.preferences.messages', false);

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'category' => 'application',
            'channel' => 'in_app',
            'enabled' => false,
        ]);

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'category' => 'message',
            'channel' => 'in_app',
            'enabled' => false,
        ]);
    }

    public function test_the_toggles_are_also_accepted_at_the_top_level(): void
    {
        $user = $this->user();

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/notifications/preferences', ['messages' => false])
            ->assertStatus(200);

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'category' => 'message',
            'channel' => 'in_app',
            'enabled' => false,
        ]);
    }

    public function test_the_preferences_payload_exposes_the_toggle_keys(): void
    {
        $user = $this->user();

        NotificationPreference::forceCreate([
            'user_id' => $user->id,
            'category' => 'message',
            'channel' => 'in_app',
            'enabled' => false,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/notifications/preferences')
            ->assertStatus(200)
            ->assertJsonFragment([
                'key' => 'messages',
                'category' => 'message',
                'channel' => 'in_app',
                'enabled' => false,
            ]);
    }

    public function test_an_unknown_toggle_key_is_rejected(): void
    {
        Sanctum::actingAs($this->user());

        $this->putJson('/api/v1/notifications/preferences', [
            'preferences' => ['not_a_real_key' => false],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['preferences']);
    }

    public function test_disabling_new_applicant_suppresses_application_notifications(): void
    {
        $user = $this->user();

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/notifications/preferences', [
            'preferences' => ['new_applicant' => false],
        ])->assertStatus(200);

        $result = app(NotificationService::class)->dispatch(
            userId: $user->id,
            category: 'application',
            title: 'New application for a project',
        );

        $this->assertNull($result);
    }

    public function test_an_enabled_toggle_still_delivers(): void
    {
        $user = $this->user();

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/notifications/preferences', [
            'preferences' => ['new_applicant' => true],
        ])->assertStatus(200);

        $result = app(NotificationService::class)->dispatch(
            userId: $user->id,
            category: 'application',
            title: 'New application for a project',
        );

        $this->assertNotNull($result);
    }
}
