<?php

namespace Tests\Feature\Communication;

use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Relevant-notifications API: listing, filtering, unread counts,
 * mark-as-read, mark-all-as-read, and per-category/channel delivery
 * preferences — including the guarantee that a disabled preference
 * actually suppresses dispatch.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    // ─── Helpers ───────────────────────────────────────────────

    private function user(): User
    {
        $user = User::forceCreate([
            'name' => 'Notification Owner',
            'email' => 'owner_'.uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);

        return $user;
    }

    private function notification(User $user, array $overrides = []): Notification
    {
        return Notification::forceCreate(array_merge([
            'user_id' => $user->id,
            'category' => 'mentor_connection',
            'channel' => 'in_app',
            'title' => 'Test notification',
            'body' => 'Body text',
            'link' => '/test',
            'event_key' => 'evt:'.uniqid(),
            'read_at' => null,
        ], $overrides));
    }

    // ─── Authentication ────────────────────────────────────────

    public function test_notification_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
        $this->getJson('/api/v1/notifications/unread-count')->assertStatus(401);
        $this->postJson('/api/v1/notifications/read-all')->assertStatus(401);
        $this->getJson('/api/v1/notifications/preferences')->assertStatus(401);
        $this->putJson('/api/v1/notifications/preferences', [])->assertStatus(401);
        $this->postJson('/api/v1/notifications/1/read')->assertStatus(401);
    }

    // ─── Listing ───────────────────────────────────────────────

    public function test_index_lists_only_the_callers_notifications(): void
    {
        $owner = $this->user();
        $other = $this->user();

        $this->notification($owner, ['title' => 'Mine']);
        $this->notification($other, ['title' => 'Theirs']);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/notifications')->assertStatus(200);

        $response->assertJsonPath('meta.total', 1);
        $this->assertSame('Mine', $response->json('data.0.title'));
    }

    public function test_index_filters_by_category(): void
    {
        $owner = $this->user();

        $this->notification($owner, ['category' => 'mentor_connection']);
        $this->notification($owner, ['category' => 'message']);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/notifications?category=message')->assertStatus(200);

        $response->assertJsonPath('meta.total', 1);
        $this->assertSame('message', $response->json('data.0.category'));
    }

    public function test_index_filters_unread_only(): void
    {
        $owner = $this->user();

        $this->notification($owner, ['read_at' => null]);
        $this->notification($owner, ['read_at' => now()]);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/notifications?unread_only=1')->assertStatus(200);

        $response->assertJsonPath('meta.total', 1);
        $this->assertFalse($response->json('data.0.is_read'));
    }

    public function test_index_reports_unread_count_in_meta(): void
    {
        $owner = $this->user();

        $this->notification($owner, ['read_at' => null]);
        $this->notification($owner, ['read_at' => null]);
        $this->notification($owner, ['read_at' => now()]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/notifications')
            ->assertStatus(200)
            ->assertJsonPath('meta.unread_count', 2);
    }

    // ─── Unread count ──────────────────────────────────────────

    public function test_unread_count_endpoint(): void
    {
        $owner = $this->user();

        $this->notification($owner, ['read_at' => null]);
        $this->notification($owner, ['read_at' => null]);
        $this->notification($owner, ['read_at' => now()]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertStatus(200)
            ->assertJsonPath('data.unread_count', 2);
    }

    public function test_unread_count_filters_by_category(): void
    {
        $owner = $this->user();

        $this->notification($owner, ['category' => 'message', 'read_at' => null]);
        $this->notification($owner, ['category' => 'mentor_connection', 'read_at' => null]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/notifications/unread-count?category=message')
            ->assertStatus(200)
            ->assertJsonPath('data.unread_count', 1);
    }

    // ─── Mark as read ──────────────────────────────────────────

    public function test_mark_as_read_updates_the_row(): void
    {
        $owner = $this->user();
        $notification = $this->notification($owner);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertStatus(200)
            ->assertJsonPath('data.is_read', true);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_as_read_returns_404_for_another_users_notification(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $notification = $this->notification($other);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertStatus(404)
            ->assertJsonPath('code', 'NOTIFICATION_NOT_FOUND');

        // The other user's notification is untouched.
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_mark_as_read_is_idempotent(): void
    {
        $owner = $this->user();
        $notification = $this->notification($owner);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertStatus(200);
        $firstReadAt = $notification->fresh()->read_at;

        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertStatus(200);

        // Timestamp unchanged on the second call.
        $this->assertEquals($firstReadAt, $notification->fresh()->read_at);
    }

    // ─── Mark all as read ──────────────────────────────────────

    public function test_mark_all_as_read_marks_every_unread_row(): void
    {
        $owner = $this->user();

        $this->notification($owner, ['read_at' => null]);
        $this->notification($owner, ['read_at' => null]);
        $this->notification($owner, ['read_at' => null]);

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/notifications/read-all')
            ->assertStatus(200)
            ->assertJsonPath('data.marked_read', 3);

        $this->assertSame(0, Notification::where('user_id', $owner->id)->whereNull('read_at')->count());
    }

    public function test_mark_all_as_read_does_not_touch_other_users(): void
    {
        $owner = $this->user();
        $other = $this->user();

        $this->notification($owner, ['read_at' => null]);
        $otherNotification = $this->notification($other, ['read_at' => null]);

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/notifications/read-all')->assertStatus(200);

        $this->assertNull($otherNotification->fresh()->read_at);
    }

    // ─── Preferences ───────────────────────────────────────────

    public function test_preferences_endpoint_returns_explicit_rows(): void
    {
        $owner = $this->user();

        NotificationPreference::forceCreate([
            'user_id' => $owner->id,
            'category' => 'message',
            'channel' => 'in_app',
            'enabled' => false,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/notifications/preferences')->assertStatus(200);

        $response->assertJsonPath('data.0.category', 'message')
            ->assertJsonPath('data.0.enabled', false);
    }

    public function test_update_preference_creates_a_row(): void
    {
        $owner = $this->user();

        Sanctum::actingAs($owner);

        $this->putJson('/api/v1/notifications/preferences', [
            'category' => 'message',
            'channel' => 'in_app',
            'enabled' => false,
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.enabled', false);

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $owner->id,
            'category' => 'message',
            'channel' => 'in_app',
            'enabled' => false,
        ]);
    }

    public function test_update_preference_is_idempotent_upsert(): void
    {
        $owner = $this->user();

        Sanctum::actingAs($owner);

        $this->putJson('/api/v1/notifications/preferences', [
            'category' => 'message',
            'channel' => 'in_app',
            'enabled' => false,
        ])->assertStatus(200);

        $this->putJson('/api/v1/notifications/preferences', [
            'category' => 'message',
            'channel' => 'in_app',
            'enabled' => true,
        ])->assertStatus(200);

        // One row, flipped — not two rows.
        $this->assertSame(1, NotificationPreference::where('user_id', $owner->id)->count());
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $owner->id,
            'category' => 'message',
            'channel' => 'in_app',
            'enabled' => true,
        ]);
    }

    public function test_update_preference_validates_channel(): void
    {
        Sanctum::actingAs($this->user());

        $this->putJson('/api/v1/notifications/preferences', [
            'category' => 'message',
            'channel' => 'carrier_pigeon',
            'enabled' => false,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['channel']);
    }

    public function test_update_preference_requires_all_fields(): void
    {
        Sanctum::actingAs($this->user());

        $this->putJson('/api/v1/notifications/preferences', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category', 'channel', 'enabled']);
    }

    // ─── Preference suppression (the behaviour that matters) ────

    public function test_disabled_preference_suppresses_dispatch(): void
    {
        $owner = $this->user();

        // Disable the 'message' category for this user.
        NotificationPreference::forceCreate([
            'user_id' => $owner->id,
            'category' => 'message',
            'channel' => 'in_app',
            'enabled' => false,
        ]);

        $service = app(NotificationService::class);
        $result = $service->dispatch(
            userId: $owner->id,
            category: 'message',
            title: 'Should not appear',
        );

        $this->assertNull($result);
        $this->assertSame(0, Notification::where('user_id', $owner->id)->count());
    }

    public function test_enabled_preference_allows_dispatch(): void
    {
        $owner = $this->user();

        NotificationPreference::forceCreate([
            'user_id' => $owner->id,
            'category' => 'message',
            'channel' => 'in_app',
            'enabled' => true,
        ]);

        $service = app(NotificationService::class);
        $result = $service->dispatch(
            userId: $owner->id,
            category: 'message',
            title: 'Should appear',
        );

        $this->assertNotNull($result);
        $this->assertSame(1, Notification::where('user_id', $owner->id)->count());
    }

    public function test_absent_preference_defaults_to_enabled(): void
    {
        $owner = $this->user();

        $service = app(NotificationService::class);
        $result = $service->dispatch(
            userId: $owner->id,
            category: 'never_configured',
            title: 'Default allow',
        );

        $this->assertNotNull($result);
    }

    public function test_dispatch_is_idempotent_on_event_key(): void
    {
        $owner = $this->user();
        $service = app(NotificationService::class);

        $first = $service->dispatch(
            userId: $owner->id,
            category: 'message',
            title: 'Once',
            eventKey: 'fixed-event-key',
        );

        $second = $service->dispatch(
            userId: $owner->id,
            category: 'message',
            title: 'Once',
            eventKey: 'fixed-event-key',
        );

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, Notification::where('user_id', $owner->id)->count());
    }

    // ─── API security ──────────────────────────────────────────

    public function test_x_request_id_propagated(): void
    {
        Sanctum::actingAs($this->user());

        $response = $this->getJson('/api/v1/notifications', ['X-Request-ID' => 'notif-req-1']);

        $response->assertStatus(200);
        $this->assertSame('notif-req-1', $response->headers->get('X-Request-ID'));
    }

    public function test_error_response_includes_request_id(): void
    {
        Sanctum::actingAs($this->user());

        $response = $this->postJson('/api/v1/notifications/999999/read', [], ['X-Request-ID' => 'notif-err-1']);

        $response->assertStatus(404);
        $this->assertSame('notif-err-1', $response->headers->get('X-Request-ID'));
        $this->assertSame('notif-err-1', $response->json('request_id'));
    }
}
