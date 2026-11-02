<?php

namespace Tests\Feature\Company;

use App\Models\Notification;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The notification and organization-profile surface the Company Dashboard
 * depends on (US-COMP-03 §2, §5, §6 and AC-04 / AC-10 / AC-11 / BR-11).
 *
 * The dashboard's own tests live in CompanyDashboardTest; these pin the two
 * endpoints it reads alongside the summary, including the three places where
 * the frontend's contract and the existing API had drifted apart:
 *
 *   - it sends PATCH .../read, the API only answered POST;
 *   - it sends ?unread=1, the API only understood ?unread_only=1;
 *   - it sends ?per_page=, the API ignored it entirely.
 */
class CompanyNotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private Role $companyAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyAdminRole = Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

    private function organization(string $status = 'verified', array $attributes = []): Organization
    {
        return Organization::forceCreate(array_merge([
            'name' => 'Acme',
            'type' => 'company',
            'verification_status' => $status,
            'contact_email' => 'contact@acme.example',
        ], $attributes));
    }

    private function companyAdmin(Organization $organization): User
    {
        $user = User::forceCreate([
            'name' => 'Company Admin',
            'email' => uniqid('company-admin-').'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($this->companyAdminRole->id, ['organization_id' => $organization->id]);

        OrganizationMember::forceCreate([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_in_org' => 'admin',
            'status' => 'active',
        ]);

        return $user;
    }

    private function notification(User $user, bool $read = false): Notification
    {
        return Notification::forceCreate([
            'user_id' => $user->id,
            'category' => 'project',
            'channel' => 'in_app',
            'title' => 'Something happened',
            'body' => 'Body',
            'event_key' => uniqid('evt-'),
            'read_at' => $read ? now() : null,
        ]);
    }

    // -----------------------------------------------------------------
    // AC-10 — mark as read, and the read state persists
    // -----------------------------------------------------------------

    public function test_patch_marks_a_notification_as_read_and_it_stays_read(): void
    {
        $admin = $this->companyAdmin($this->organization());
        $notification = $this->notification($admin);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/notifications/{$notification->id}/read")->assertOk();

        $this->assertNotNull($notification->fresh()->read_at);

        // AC-10 — a second read of the list still reports it as read.
        $list = $this->getJson('/api/v1/notifications')->assertOk()->json('data');

        $this->assertSame(0, $this->getJson('/api/v1/notifications')->json('meta.unread_count'));
        $this->assertNotEmpty($list);
    }

    public function test_post_still_marks_a_notification_as_read(): void
    {
        $admin = $this->companyAdmin($this->organization());
        $notification = $this->notification($admin);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_a_representative_cannot_mark_someone_elses_notification_as_read(): void
    {
        $admin = $this->companyAdmin($this->organization());

        $stranger = User::forceCreate([
            'name' => 'Stranger',
            'email' => uniqid('stranger-').'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $foreign = $this->notification($stranger);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/notifications/{$foreign->id}/read")->assertNotFound();

        $this->assertNull($foreign->fresh()->read_at);
    }

    // -----------------------------------------------------------------
    // AC-11 / BR-11 — filters and pagination
    // -----------------------------------------------------------------

    public function test_the_unread_alias_filters_the_list(): void
    {
        $admin = $this->companyAdmin($this->organization());

        $this->notification($admin);
        $this->notification($admin);
        $this->notification($admin, read: true);

        Sanctum::actingAs($admin);

        $all = $this->getJson('/api/v1/notifications')->assertOk()->json();
        $this->assertSame(3, $all['meta']['total']);

        $unread = $this->getJson('/api/v1/notifications?unread=1')->assertOk()->json();
        $this->assertSame(2, $unread['meta']['total']);
        $this->assertSame(2, $unread['meta']['unread_count']);

        // The original spelling keeps working.
        $legacy = $this->getJson('/api/v1/notifications?unread_only=1')->assertOk()->json();
        $this->assertSame(2, $legacy['meta']['total']);
    }

    public function test_per_page_is_honoured_and_clamped_to_the_configured_maximum(): void
    {
        $admin = $this->companyAdmin($this->organization());

        foreach (range(1, 60) as $ignored) {
            $this->notification($admin);
        }

        Sanctum::actingAs($admin);

        $small = $this->getJson('/api/v1/notifications?per_page=5')->assertOk()->json();
        $this->assertSame(5, $small['meta']['per_page']);
        $this->assertCount(5, $small['data']);

        // BR-11 — asking for more than the maximum returns the maximum, it does
        // not fail and it does not return everything.
        $huge = $this->getJson('/api/v1/notifications?per_page=1000')->assertOk()->json();
        $this->assertSame(50, $huge['meta']['per_page']);
        $this->assertCount(50, $huge['data']);
        $this->assertSame(60, $huge['meta']['total']);
    }

    public function test_the_list_reports_the_unread_total_for_the_badge(): void
    {
        $admin = $this->companyAdmin($this->organization());

        $this->notification($admin);
        $this->notification($admin);

        Sanctum::actingAs($admin);

        $this->assertSame(2, $this->getJson('/api/v1/notifications')->json('meta.unread_count'));
        $this->assertSame(2, $this->getJson('/api/v1/notifications/unread-count')->json('data.unread_count'));
    }

    // -----------------------------------------------------------------
    // AC-03 / AC-04 — organization status and completeness
    // -----------------------------------------------------------------

    public function test_the_organization_profile_reports_completeness_and_missing_fields(): void
    {
        $organization = $this->organization(attributes: [
            'contact_email' => 'hello@acme.example',
            'country' => null,
            'city' => null,
            'company_size' => null,
            'website' => null,
            'description' => null,
        ]);

        $admin = $this->companyAdmin($organization);

        Sanctum::actingAs($admin);

        $data = $this->getJson('/api/v1/organization/profile')->assertOk()->json('data');

        // name + type are always set, contact_email is set, the other three
        // groups are empty → 2 of 6.
        $this->assertSame(33, $data['profile_completeness']);
        $this->assertSame('verified', $data['verification_status']);

        $fields = collect($data['profile_fields'])->keyBy('key');
        $this->assertTrue($fields['info']['done']);
        $this->assertTrue($fields['contact']['done']);
        $this->assertFalse($fields['address']['done']);
        $this->assertFalse($fields['size']['done']);
        $this->assertFalse($fields['website']['done']);
        $this->assertFalse($fields['description']['done']);
    }

    public function test_a_fully_filled_profile_is_one_hundred_percent(): void
    {
        $organization = $this->organization(attributes: [
            'contact_email' => 'hello@acme.example',
            'contact_phone' => '+970000000',
            'country' => 'PS',
            'city' => 'Gaza',
            'company_size' => '11-50',
            'website' => 'https://acme.example',
            'description' => 'We build things.',
        ]);

        $admin = $this->companyAdmin($organization);

        Sanctum::actingAs($admin);

        $data = $this->getJson('/api/v1/organization/profile')->assertOk()->json('data');

        $this->assertSame(100, $data['profile_completeness']);
        $this->assertSame([], array_filter($data['profile_fields'], fn ($field) => $field['done'] === false));
    }

    public function test_a_pending_organization_cannot_read_its_profile_through_the_approved_gate(): void
    {
        $organization = $this->organization('pending');
        $admin = $this->companyAdmin($organization);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/organization/profile')
            ->assertForbidden()
            ->assertJsonPath('code', 'ORGANIZATION_PENDING');
    }

    // -----------------------------------------------------------------
    // Database task list — a notification knows its organization and entity
    // -----------------------------------------------------------------

    /**
     * The story asks a notification to carry the organization it belongs to and
     * the record it is about, not just a free-text `link`. US-COMP-03 itself
     * never reads the two columns — they exist so US-PRJ-01 can emit company
     * events that the dashboard can scope and open.
     */
    public function test_a_dispatched_notification_records_its_organization_and_entity(): void
    {
        $organization = $this->organization();
        $admin = $this->companyAdmin($organization);

        $notification = app(NotificationService::class)->dispatch(
            userId: $admin->id,
            category: 'project',
            title: 'Your project was approved',
            link: '/company/projects/7',
            eventKey: 'project.approved:7',
            organizationId: $organization->id,
            entityType: 'project',
            entityId: 7,
        );

        $this->assertNotNull($notification);
        $this->assertSame($organization->id, $notification->organization_id);
        $this->assertSame('project', $notification->entity_type);
        $this->assertSame(7, $notification->entity_id);
        $this->assertSame($organization->id, $notification->organization->id);
    }

    /**
     * Every row written before the columns existed, and every mentor/student
     * notification after them, has no organization — and must stay valid.
     */
    public function test_a_notification_without_an_organization_stays_valid(): void
    {
        $user = User::forceCreate([
            'name' => 'Learner',
            'email' => uniqid('learner-').'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $notification = app(NotificationService::class)->dispatch(
            userId: $user->id,
            category: 'mentor',
            title: 'Your mentor replied',
        );

        $this->assertNotNull($notification);
        $this->assertNull($notification->organization_id);
        $this->assertNull($notification->entity_type);
        $this->assertNull($notification->entity_id);

        // And it still lists and counts for its recipient.
        Sanctum::actingAs($user);

        $this->assertSame(1, $this->getJson('/api/v1/notifications')->json('meta.total'));
    }
}
