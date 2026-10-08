<?php

namespace Tests\Feature\Admin;

use App\Models\CareerRole;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\SupportMessage;
use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The support inbox — the administrator and mentor side.
 *
 * Two audiences share one page, separated by visibility rather than by two
 * different screens:
 *
 *   - an administrator sees EVERY request, because they are the safety net for
 *     the ones no mentor is connected to;
 *   - a mentor sees ONLY their own assignments.
 *
 * That split is the thing most worth testing, so most of these cases are about
 * who can read what. The page is session-authenticated, so `actingAs` is used
 * rather than a Sanctum token.
 */
class AdminSupportPanelTest extends TestCase
{
    use RefreshDatabase;

    private Role $learnerRole;

    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        $this->adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
    }

    // ------------------------------------------------------------ page access

    public function test_a_guest_is_redirected_to_the_admin_login(): void
    {
        $this->get('/admin/support')->assertRedirect('/admin/login');
    }

    public function test_the_page_renders_for_an_administrator(): void
    {
        $this->actingAs($this->administrator())
            ->get('/admin/support')
            ->assertOk()
            ->assertSee('Support', false)
            ->assertSee('Queue', false);
    }

    public function test_the_page_renders_for_a_mentor(): void
    {
        // A mentor is notified when a learner is routed to them, so the page
        // their notification links to has to open for them — that is the whole
        // reason this route is not behind the `admin` middleware.
        $this->actingAs($this->mentor())
            ->get('/admin/support')
            ->assertOk();
    }

    public function test_the_page_is_refused_to_an_unrelated_account(): void
    {
        $this->actingAs($this->learner())
            ->get('/admin/support')
            ->assertStatus(403);
    }

    // ----------------------------------------------------------- API access

    public function test_an_unrelated_account_is_refused_by_the_api(): void
    {
        $this->actingAs($this->learner())
            ->getJson('/admin/api/support')
            ->assertStatus(403)
            ->assertJsonPath('code', 'SUPPORT_FORBIDDEN');
    }

    public function test_a_guest_cannot_reach_the_api(): void
    {
        $this->getJson('/admin/api/support')->assertStatus(401);
    }

    // ------------------------------------------------------------- visibility

    public function test_an_administrator_sees_every_request(): void
    {
        $mentor = $this->mentor();
        $mine = $this->supportRequest($this->learner(), assignedTo: $mentor);
        $unclaimed = $this->supportRequest($this->learner());

        $this->actingAs($this->administrator())
            ->getJson('/admin/api/support')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('viewer.is_admin', true);

        $ids = collect($this->actingAs($this->administrator())
            ->getJson('/admin/api/support')
            ->json('data.data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$mine->id, $unclaimed->id], $ids);
    }

    public function test_a_mentor_sees_only_their_own_assignments(): void
    {
        $mentor = $this->mentor();
        $otherMentor = $this->mentor();

        $theirs = $this->supportRequest($this->learner(), assignedTo: $mentor);
        $notTheirs = $this->supportRequest($this->learner(), assignedTo: $otherMentor);
        $unclaimed = $this->supportRequest($this->learner());

        $response = $this->actingAs($mentor)->getJson('/admin/api/support')->assertOk();

        $response
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('viewer.is_mentor', true)
            ->assertJsonPath('viewer.is_admin', false)
            ->assertJsonPath('data.data.0.id', $theirs->id);

        $this->assertNotContains($notTheirs->id, collect($response->json('data.data'))->pluck('id')->all());
        $this->assertNotContains($unclaimed->id, collect($response->json('data.data'))->pluck('id')->all());
    }

    public function test_a_mentor_cannot_open_another_mentors_request(): void
    {
        $mentor = $this->mentor();
        $otherMentor = $this->mentor();

        $foreign = $this->supportRequest($this->learner(), assignedTo: $otherMentor);

        // 404, not 403: the inbox must not confirm that someone else's request
        // exists.
        $this->actingAs($mentor)
            ->getJson('/admin/api/support/'.$foreign->id)
            ->assertStatus(404)
            ->assertJsonPath('code', 'SUPPORT_REQUEST_NOT_FOUND');
    }

    public function test_an_administrator_can_open_any_request(): void
    {
        $foreign = $this->supportRequest($this->learner(), assignedTo: $this->mentor());

        $this->actingAs($this->administrator())
            ->getJson('/admin/api/support/'.$foreign->id)
            ->assertOk()
            ->assertJsonPath('data.id', $foreign->id);
    }

    // --------------------------------------------------------------- the page

    public function test_the_detail_carries_the_learner_and_the_thread(): void
    {
        $learner = $this->learner();
        $request = $this->supportRequest($learner);

        $this->actingAs($this->administrator())
            ->getJson('/admin/api/support/'.$request->id)
            ->assertOk()
            ->assertJsonPath('data.user_id', $learner->id)
            ->assertJsonPath('data.user_name', $learner->name)
            ->assertJsonPath('data.user_email', $learner->email)
            ->assertJsonCount(1, 'data.messages');
    }

    // -------------------------------------------------------------- messaging

    public function test_a_support_reply_reaches_the_learner_and_notifies_them(): void
    {
        $learner = $this->learner();
        $mentor = $this->mentor();
        $request = $this->supportRequest($learner, assignedTo: $mentor);

        $this->actingAs($mentor)
            ->postJson('/admin/api/support/'.$request->id.'/messages', [
                'body' => 'Open the project page and press Publish.',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.body', 'Open the project page and press Publish.')
            ->assertJsonPath('data.sender_id', $mentor->id);

        $this->assertDatabaseHas('support_messages', [
            'support_request_id' => $request->id,
            'sender_id' => $mentor->id,
            'body' => 'Open the project page and press Publish.',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $learner->id,
            'category' => 'support_request',
        ]);
    }

    public function test_the_first_reply_claims_an_unclaimed_request(): void
    {
        // An administrator, not a mentor: a mentor only ever sees requests
        // already assigned to them, so the unclaimed ones are the
        // administrator's queue by construction.
        $admin = $this->administrator();
        $request = $this->supportRequest($this->learner());

        $this->assertSame(SupportRequest::STATUS_PENDING, $request->status);
        $this->assertNull($request->assigned_to);

        $this->actingAs($admin)
            ->postJson('/admin/api/support/'.$request->id.'/messages', ['body' => 'On it.'])
            ->assertStatus(201);

        $request->refresh();

        // Claiming on the first reply means a thread can never be answered by
        // someone who is not recorded as its owner.
        $this->assertSame(SupportRequest::STATUS_ASSIGNED, $request->status);
        $this->assertSame($admin->id, $request->assigned_to);
        $this->assertNotNull($request->assigned_at);
    }

    public function test_a_mentor_cannot_reach_an_unclaimed_request(): void
    {
        // An unclaimed request was never routed to this mentor, so it is not
        // theirs to read or claim — only the administrator sees it.
        $request = $this->supportRequest($this->learner());

        $this->actingAs($this->mentor())
            ->getJson('/admin/api/support/'.$request->id)
            ->assertStatus(404);

        $this->actingAs($this->mentor())
            ->postJson('/admin/api/support/'.$request->id.'/messages', ['body' => 'intrusion'])
            ->assertStatus(404);

        $this->assertNull($request->fresh()->assigned_to);
    }

    public function test_a_closed_thread_refuses_new_replies(): void
    {
        $request = $this->supportRequest($this->learner(), assignedTo: $this->mentor());
        $request->forceFill(['status' => SupportRequest::STATUS_RESOLVED, 'resolved_at' => now()])->save();

        $this->actingAs($this->administrator())
            ->postJson('/admin/api/support/'.$request->id.'/messages', ['body' => 'too late'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SUPPORT_REQUEST_CLOSED');
    }

    public function test_an_empty_reply_is_refused(): void
    {
        $request = $this->supportRequest($this->learner(), assignedTo: $this->mentor());

        $this->actingAs($this->administrator())
            ->postJson('/admin/api/support/'.$request->id.'/messages', ['body' => ''])
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------- actions

    public function test_an_administrator_can_claim_an_unclaimed_request(): void
    {
        $admin = $this->administrator();
        $request = $this->supportRequest($this->learner());

        $this->actingAs($admin)
            ->postJson('/admin/api/support/'.$request->id.'/assign')
            ->assertOk()
            ->assertJsonPath('data.status', SupportRequest::STATUS_ASSIGNED)
            ->assertJsonPath('data.assigned_to', $admin->id);
    }

    public function test_claiming_an_already_claimed_request_does_not_steal_it(): void
    {
        $mentor = $this->mentor();
        $request = $this->supportRequest($this->learner(), assignedTo: $mentor);

        // Stealing a thread mid-conversation would silently drop the current
        // owner out of a conversation the learner believes they are in.
        $this->actingAs($this->administrator())
            ->postJson('/admin/api/support/'.$request->id.'/assign')
            ->assertOk()
            ->assertJsonPath('data.assigned_to', $mentor->id);

        $this->assertSame($mentor->id, $request->fresh()->assigned_to);
    }

    public function test_resolving_closes_the_request_and_notifies_the_learner(): void
    {
        $learner = $this->learner();
        $mentor = $this->mentor();
        $request = $this->supportRequest($learner, assignedTo: $mentor);

        $this->actingAs($mentor)
            ->postJson('/admin/api/support/'.$request->id.'/resolve')
            ->assertOk()
            ->assertJsonPath('data.status', SupportRequest::STATUS_RESOLVED);

        $request->refresh();

        $this->assertSame(SupportRequest::STATUS_RESOLVED, $request->status);
        $this->assertNotNull($request->resolved_at);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $learner->id,
            'event_key' => 'support_resolved:'.$request->id,
        ]);
    }

    // ------------------------------------------------------------- read state

    public function test_unread_is_relative_to_the_viewer(): void
    {
        $learner = $this->learner();
        $mentor = $this->mentor();
        $request = $this->supportRequest($learner, assignedTo: $mentor);

        // A learner message the mentor has not opened.
        SupportMessage::create([
            'support_request_id' => $request->id,
            'sender_id' => $learner->id,
            'body' => 'Any update?',
            'message_type' => SupportMessage::TYPE_TEXT,
        ]);

        $this->actingAs($mentor)
            ->getJson('/admin/api/support')
            ->assertOk()
            ->assertJsonPath('data.data.0.has_unread', true)
            ->assertJsonPath('stats.unread', 1);
    }

    public function test_a_mentors_own_reply_does_not_count_as_unread_for_them(): void
    {
        $learner = $this->learner();
        $mentor = $this->mentor();
        $request = $this->supportRequest($learner, assignedTo: $mentor);

        $this->actingAs($mentor)
            ->postJson('/admin/api/support/'.$request->id.'/messages', ['body' => 'Answered.'])
            ->assertStatus(201);

        // Marking your own message read would make the learner's "support has
        // seen this" signal meaningless, so it must not count either way.
        $this->actingAs($mentor)
            ->getJson('/admin/api/support')
            ->assertOk()
            ->assertJsonPath('data.data.0.has_unread', false)
            ->assertJsonPath('stats.unread', 0);
    }

    public function test_opening_a_thread_marks_the_learners_messages_read(): void
    {
        $learner = $this->learner();
        $mentor = $this->mentor();
        $request = $this->supportRequest($learner, assignedTo: $mentor);

        $message = SupportMessage::create([
            'support_request_id' => $request->id,
            'sender_id' => $learner->id,
            'body' => 'Any update?',
            'message_type' => SupportMessage::TYPE_TEXT,
        ]);

        $this->actingAs($mentor)
            ->getJson('/admin/api/support/'.$request->id)
            ->assertOk();

        $message->refresh();

        $this->assertNotNull($message->read_at);
        $this->assertSame($mentor->id, $message->read_by);
    }

    // -------------------------------------------------------------- filtering

    public function test_the_queue_can_be_filtered_by_status(): void
    {
        $mentor = $this->mentor();
        $this->supportRequest($this->learner(), assignedTo: $mentor);
        $resolved = $this->supportRequest($this->learner(), assignedTo: $mentor);
        $resolved->forceFill(['status' => SupportRequest::STATUS_RESOLVED, 'resolved_at' => now()])->save();

        $this->actingAs($mentor)
            ->getJson('/admin/api/support?status=resolved')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.id', $resolved->id);
    }

    public function test_the_queue_can_be_searched_by_learner_name(): void
    {
        $mentor = $this->mentor();
        $named = $this->learner(name: 'Zainab Al-Rashid');
        $other = $this->learner(name: 'Someone Else');

        $found = $this->supportRequest($named, assignedTo: $mentor);
        $this->supportRequest($other, assignedTo: $mentor);

        $this->actingAs($mentor)
            ->getJson('/admin/api/support?q=Zainab')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.id', $found->id);
    }

    public function test_the_open_scope_excludes_resolved_requests(): void
    {
        $mentor = $this->mentor();
        $open = $this->supportRequest($this->learner(), assignedTo: $mentor);
        $closed = $this->supportRequest($this->learner(), assignedTo: $mentor);
        $closed->forceFill(['status' => SupportRequest::STATUS_RESOLVED, 'resolved_at' => now()])->save();

        $this->actingAs($mentor)
            ->getJson('/admin/api/support?scope=open')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.id', $open->id);
    }

    // ------------------------------------------------------------- the stats

    public function test_the_stats_are_scoped_like_the_list(): void
    {
        $mentor = $this->mentor();
        $otherMentor = $this->mentor();

        $this->supportRequest($this->learner(), assignedTo: $mentor);
        $this->supportRequest($this->learner(), assignedTo: $mentor);
        $this->supportRequest($this->learner(), assignedTo: $otherMentor);

        // A card reading "3" while the list shows two is worse than no card.
        $this->actingAs($mentor)
            ->getJson('/admin/api/support')
            ->assertOk()
            ->assertJsonPath('stats.total', 2)
            ->assertJsonPath('stats.assigned', 2)
            ->assertJsonPath('stats.pending', 0);
    }

    // --------------------------------------------------------------- helpers

    private function administrator(): User
    {
        $user = $this->user('Panel Admin');
        $user->roles()->attach($this->adminRole->id);

        return $user->fresh();
    }

    private function mentor(): User
    {
        $user = $this->user('Support Mentor');

        // Mentor identity is a ProfessionalProfile attribute, not a role slug.
        ProfessionalProfile::create([
            'user_id' => $user->id,
            'type' => 'mentor',
            'expertise' => 'Backend engineering',
        ]);

        return $user->fresh();
    }

    private function learner(string $name = 'Support Learner'): User
    {
        $user = $this->user($name);
        $user->roles()->attach($this->learnerRole->id);

        $role = CareerRole::forceCreate([
            'title' => 'Data Analyst',
            'slug' => 'data-analyst-'.uniqid(),
            'version' => 1,
            'status' => 'approved',
        ]);

        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'availability' => 'full_time',
            'primary_career_role_id' => $role->id,
        ]);

        return $user->fresh();
    }

    private function supportRequest(User $learner, ?User $assignedTo = null): SupportRequest
    {
        $request = SupportRequest::create([
            'user_id' => $learner->id,
            'student_profile_id' => $learner->studentProfile?->id,
            'assigned_to' => $assignedTo?->id,
            'status' => $assignedTo !== null
                ? SupportRequest::STATUS_ASSIGNED
                : SupportRequest::STATUS_PENDING,
            'reason' => SupportRequest::REASON_INSUFFICIENT_CONTEXT,
            'subject' => 'The assistant could not answer',
            'transcript' => [
                ['role' => 'learner', 'body' => 'How do I publish my project?'],
                ['role' => 'assistant', 'body' => 'I could not find that.'],
            ],
            'assigned_at' => $assignedTo !== null ? now() : null,
            'last_message_at' => now(),
        ]);

        // The handoff marker the real flow always writes. Created here because
        // these tests are about the inbox, not about the handoff itself — that
        // path has its own coverage in Tests\Feature\Support\SupportHandoffTest.
        SupportMessage::create([
            'support_request_id' => $request->id,
            'sender_id' => $learner->id,
            'body' => 'Transferring you to technical support.',
            'message_type' => SupportMessage::TYPE_SYSTEM,
        ]);

        return $request;
    }

    private function user(string $name): User
    {
        // `example.com`, never `test.com`: test.com addresses are disposable
        // under the `indisposable` rule, so assertions about them can pass for
        // the wrong reason.
        return User::forceCreate([
            'name' => $name,
            'email' => uniqid().'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
