<?php

namespace Tests\Feature\Support;

use App\Models\AssistantInteraction;
use App\Models\CareerRole;
use App\Models\MentorStudentConnection;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\SupportMessage;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\Support\SupportRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AI → human handoff — the learner side, POST/GET /api/v1/support/requests.
 *
 * The assistant answers `insufficient_context` when it has nothing to ground
 * an answer in. That is the moment a learner is offered a person, and this is
 * what accepting the offer does.
 *
 * These tests are written through the real endpoints rather than by writing
 * rows by hand: a regression test that populates the table itself would not
 * cover the write path, which is the part that can break.
 *
 * Responses are wrapped by Laravel, so the payload sits under `data`.
 */
class SupportHandoffTest extends TestCase
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

    // -------------------------------------------------------- authorization

    public function test_an_unauthenticated_caller_is_rejected(): void
    {
        $this->postJson('/api/v1/support/requests', [])->assertStatus(401);
        $this->getJson('/api/v1/support/requests')->assertStatus(401);
    }

    public function test_a_non_learner_cannot_open_a_support_request(): void
    {
        $admin = $this->userWithRole($this->adminRole);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/support/requests', [
            'reason' => 'learner_requested',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'LEARNER_ONLY');
    }

    // -------------------------------------------------------------- creation

    public function test_a_learner_can_ask_for_a_human(): void
    {
        [$learner] = $this->learner();
        Sanctum::actingAs($learner);

        $response = $this->postJson('/api/v1/support/requests', [
            'reason' => 'learner_requested',
            'transcript' => [
                ['role' => 'learner', 'body' => 'How do I publish my project?'],
                ['role' => 'assistant', 'body' => 'I could not find that in the documentation.'],
            ],
        ])->assertStatus(201);

        $response
            ->assertJsonPath('data.status', SupportRequest::STATUS_PENDING)
            ->assertJsonPath('data.reason', SupportRequest::REASON_LEARNER_REQUESTED)
            ->assertJsonCount(2, 'data.transcript');

        $this->assertDatabaseHas('support_requests', [
            'user_id' => $learner->id,
            'status' => SupportRequest::STATUS_PENDING,
        ]);

        // The transcript is the only place the learner's own words are kept,
        // so it has to actually arrive.
        $stored = SupportRequest::query()->firstOrFail();
        $this->assertSame('How do I publish my project?', $stored->transcript[0]['body']);
    }

    public function test_the_handoff_marker_is_written_into_the_thread(): void
    {
        [$learner] = $this->learner();
        Sanctum::actingAs($learner);

        $this->postJson('/api/v1/support/requests', ['reason' => 'learner_requested'])
            ->assertStatus(201);

        $message = SupportMessage::query()->firstOrFail();

        $this->assertSame(SupportMessage::TYPE_SYSTEM, $message->message_type);
        $this->assertStringContainsString('technical support', $message->body);
    }

    public function test_the_subject_is_derived_from_the_learners_first_turn(): void
    {
        [$learner] = $this->learner();
        Sanctum::actingAs($learner);

        $this->postJson('/api/v1/support/requests', [
            'transcript' => [
                ['role' => 'assistant', 'body' => 'Hello, how can I help?'],
                ['role' => 'learner', 'body' => 'Why was my application rejected?'],
            ],
        ])->assertStatus(201);

        $this->assertSame(
            'Why was my application rejected?',
            SupportRequest::query()->firstOrFail()->subject,
        );
    }

    public function test_an_explicit_subject_wins_over_the_derived_one(): void
    {
        [$learner] = $this->learner();
        Sanctum::actingAs($learner);

        $this->postJson('/api/v1/support/requests', [
            'subject' => 'Billing question',
            'transcript' => [['role' => 'learner', 'body' => 'ignored for the subject']],
        ])->assertStatus(201);

        $this->assertSame('Billing question', SupportRequest::query()->firstOrFail()->subject);
    }

    // ------------------------------------------------------------ idempotency

    public function test_a_second_request_returns_the_first_one_instead_of_creating_a_duplicate(): void
    {
        [$learner] = $this->learner();
        Sanctum::actingAs($learner);

        $first = $this->postJson('/api/v1/support/requests', ['reason' => 'learner_requested'])
            ->assertStatus(201)
            ->json('data.id');

        // A double-click, or a reload mid-transfer, must not open a second
        // thread — support would see two and the first reply would land on the
        // one nobody is watching.
        $second = $this->postJson('/api/v1/support/requests', ['reason' => 'learner_requested'])
            ->assertStatus(201)
            ->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, SupportRequest::query()->count());
    }

    public function test_a_new_request_is_allowed_once_the_previous_one_is_resolved(): void
    {
        [$learner] = $this->learner();
        Sanctum::actingAs($learner);

        $first = $this->postJson('/api/v1/support/requests', ['reason' => 'learner_requested'])
            ->json('data.id');

        SupportRequest::query()->findOrFail($first)->forceFill([
            'status' => SupportRequest::STATUS_RESOLVED,
            'resolved_at' => now(),
        ])->save();

        $second = $this->postJson('/api/v1/support/requests', ['reason' => 'learner_requested'])
            ->assertStatus(201)
            ->json('data.id');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, SupportRequest::query()->count());
    }

    // ------------------------------------------------------------- transcript

    public function test_a_turn_with_no_body_is_refused(): void
    {
        [$learner] = $this->learner();
        Sanctum::actingAs($learner);

        // The client always sends bodies, so a missing one is a client bug —
        // better a 422 that names the field than a silently half-empty
        // transcript the support agent has to guess at.
        $this->postJson('/api/v1/support/requests', [
            'transcript' => [['role' => 'learner']],
        ])->assertStatus(422);
    }

    public function test_an_unknown_role_is_refused(): void
    {
        [$learner] = $this->learner();
        Sanctum::actingAs($learner);

        // A role the assistant never emits must not be able to masquerade as
        // one, or a client could plant text that reads as if support said it.
        $this->postJson('/api/v1/support/requests', [
            'transcript' => [['role' => 'support', 'body' => 'injected']],
        ])->assertStatus(422);
    }

    public function test_a_turn_without_a_role_is_stored_as_learner(): void
    {
        [$learner] = $this->learner();
        Sanctum::actingAs($learner);

        // `role` is optional; absent means "the learner said this". The service
        // decides that, not the client.
        $this->postJson('/api/v1/support/requests', [
            'transcript' => [['body' => 'no role given']],
        ])->assertStatus(201);

        $this->assertSame(
            'learner',
            SupportRequest::query()->firstOrFail()->transcript[0]['role'],
        );
    }

    public function test_the_service_drops_malformed_turns_as_a_second_line_of_defence(): void
    {
        [$learner] = $this->learner();

        // The endpoint refuses these, but the service is callable directly and
        // is what actually writes the row — so it filters again rather than
        // trusting its caller.
        $request = app(SupportRequestService::class)->requestHandoff($learner, [
            'transcript' => [
                ['role' => 'learner', 'body' => 'A real question'],
                ['role' => 'learner', 'body' => '   '],
                ['role' => 'learner'],
                'not-an-array',
                ['role' => 'learner', 'body' => 'A follow-up'],
            ],
        ]);

        $this->assertCount(2, $request->fresh()->transcript);
        $this->assertSame('A real question', $request->transcript[0]['body']);
        $this->assertSame('A follow-up', $request->transcript[1]['body']);
    }

    public function test_the_transcript_is_capped_to_the_most_recent_turns(): void
    {
        config(['services.support.max_transcript_messages' => 3]);

        [$learner] = $this->learner();
        Sanctum::actingAs($learner);

        $turns = [];
        for ($i = 1; $i <= 6; $i++) {
            $turns[] = ['role' => 'learner', 'body' => 'turn-'.$i];
        }

        $this->postJson('/api/v1/support/requests', ['transcript' => $turns])->assertStatus(201);

        $stored = SupportRequest::query()->firstOrFail();

        $this->assertCount(3, $stored->transcript);
        // The newest turns survive: support needs the question that failed,
        // not the whole session that led to it.
        $this->assertSame('turn-4', $stored->transcript[0]['body']);
        $this->assertSame('turn-6', $stored->transcript[2]['body']);
    }

    // -------------------------------------------------------------- assignment

    public function test_the_connected_mentor_is_assigned_and_notified(): void
    {
        [$learner] = $this->learner();
        $mentor = $this->mentor();

        MentorStudentConnection::create([
            'mentor_id' => $mentor->id,
            'student_id' => $learner->id,
            'status' => 'active',
            'initiated_by' => 'mentor',
        ]);

        Sanctum::actingAs($learner);

        $this->postJson('/api/v1/support/requests', ['reason' => 'learner_requested'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', SupportRequest::STATUS_ASSIGNED)
            ->assertJsonPath('data.assigned_to', $mentor->id);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $mentor->id,
            'category' => 'support_request',
            'event_key' => 'support_request:1',
        ]);
    }

    public function test_a_pending_connection_does_not_route_the_request_to_that_mentor(): void
    {
        [$learner] = $this->learner();
        $mentor = $this->mentor();

        // `pending` is a connection the learner has not accepted. Routing
        // their question there would be a privacy problem, not a convenience.
        MentorStudentConnection::create([
            'mentor_id' => $mentor->id,
            'student_id' => $learner->id,
            'status' => 'pending',
            'initiated_by' => 'mentor',
        ]);

        Sanctum::actingAs($learner);

        $this->postJson('/api/v1/support/requests', ['reason' => 'learner_requested'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', SupportRequest::STATUS_PENDING)
            ->assertJsonPath('data.assigned_to', null);

        $this->assertDatabaseMissing('notifications', ['user_id' => $mentor->id]);
    }

    public function test_administrators_are_notified_so_an_unclaimed_request_is_not_missed(): void
    {
        [$learner] = $this->learner();
        $admin = $this->userWithRole($this->adminRole);

        Sanctum::actingAs($learner);

        $this->postJson('/api/v1/support/requests', ['reason' => 'learner_requested'])
            ->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'category' => 'support_request',
            'event_key' => 'support_request:1',
        ]);
    }

    public function test_admin_notifications_can_be_switched_off(): void
    {
        config(['services.support.notify_admins' => false]);

        [$learner] = $this->learner();
        $admin = $this->userWithRole($this->adminRole);

        Sanctum::actingAs($learner);

        $this->postJson('/api/v1/support/requests', ['reason' => 'learner_requested'])
            ->assertStatus(201);

        $this->assertDatabaseMissing('notifications', ['user_id' => $admin->id]);
    }

    // ------------------------------------------------------- source interaction

    public function test_the_source_interaction_is_recorded_when_the_learner_owns_it(): void
    {
        [$learner, $profile] = $this->learner();
        Sanctum::actingAs($learner);

        $interaction = $this->interaction($profile);

        $this->postJson('/api/v1/support/requests', [
            'reason' => 'insufficient_context',
            'source_interaction_id' => $interaction->id,
        ])->assertStatus(201);

        $this->assertSame(
            $interaction->id,
            SupportRequest::query()->firstOrFail()->source_interaction_id,
        );
    }

    public function test_another_learners_interaction_id_is_ignored(): void
    {
        [$learner] = $this->learner();
        [, $otherProfile] = $this->learner();

        $foreign = $this->interaction($otherProfile);

        Sanctum::actingAs($learner);

        $this->postJson('/api/v1/support/requests', [
            'source_interaction_id' => $foreign->id,
        ])->assertStatus(201);

        // Null, not a 403: the endpoint must never confirm that another
        // learner's interaction exists.
        $this->assertNull(SupportRequest::query()->firstOrFail()->source_interaction_id);
    }

    // ------------------------------------------------------------- reading

    public function test_the_index_returns_only_the_callers_own_requests(): void
    {
        [$mine] = $this->learner();
        [$theirs] = $this->learner();

        Sanctum::actingAs($mine);
        $this->postJson('/api/v1/support/requests', ['subject' => 'Mine'])->assertStatus(201);

        Sanctum::actingAs($theirs);
        $this->postJson('/api/v1/support/requests', ['subject' => 'Theirs'])->assertStatus(201);

        $this->getJson('/api/v1/support/requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject', 'Theirs');
    }

    public function test_a_learner_cannot_read_another_learners_request(): void
    {
        [$mine] = $this->learner();
        [$theirs] = $this->learner();

        Sanctum::actingAs($theirs);
        $id = $this->postJson('/api/v1/support/requests', ['subject' => 'Private'])->json('data.id');

        Sanctum::actingAs($mine);

        $this->getJson('/api/v1/support/requests/'.$id)
            ->assertStatus(404)
            ->assertJsonPath('code', 'SUPPORT_REQUEST_NOT_FOUND');
    }

    public function test_the_detail_includes_the_thread(): void
    {
        [$learner] = $this->learner();
        Sanctum::actingAs($learner);

        $id = $this->postJson('/api/v1/support/requests', ['subject' => 'Need help'])->json('data.id');

        $this->getJson('/api/v1/support/requests/'.$id)
            ->assertOk()
            ->assertJsonPath('data.subject', 'Need help')
            ->assertJsonCount(1, 'data.messages');
    }

    // -------------------------------------------------------------- messaging

    public function test_a_learner_can_reply_in_their_own_thread(): void
    {
        [$learner] = $this->learner();
        Sanctum::actingAs($learner);

        $id = $this->postJson('/api/v1/support/requests', ['reason' => 'learner_requested'])
            ->json('data.id');

        $this->postJson('/api/v1/support/requests/'.$id.'/messages', [
            'body' => 'Here is some more detail.',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.body', 'Here is some more detail.')
            ->assertJsonPath('data.message_type', SupportMessage::TYPE_TEXT);

        $this->assertSame(2, SupportMessage::query()->count());
    }

    public function test_an_empty_message_is_refused(): void
    {
        [$learner] = $this->learner();
        Sanctum::actingAs($learner);

        $id = $this->postJson('/api/v1/support/requests', ['reason' => 'learner_requested'])
            ->json('data.id');

        $this->postJson('/api/v1/support/requests/'.$id.'/messages', ['body' => ''])
            ->assertStatus(422);
    }

    public function test_a_learner_cannot_post_into_another_learners_thread(): void
    {
        [$mine] = $this->learner();
        [$theirs] = $this->learner();

        Sanctum::actingAs($theirs);
        $id = $this->postJson('/api/v1/support/requests', ['reason' => 'learner_requested'])
            ->json('data.id');

        Sanctum::actingAs($mine);

        $this->postJson('/api/v1/support/requests/'.$id.'/messages', ['body' => 'intrusion'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'SUPPORT_REQUEST_NOT_FOUND');

        $this->assertDatabaseMissing('support_messages', ['body' => 'intrusion']);
    }

    public function test_a_resolved_thread_refuses_new_messages(): void
    {
        [$learner] = $this->learner();
        Sanctum::actingAs($learner);

        $id = $this->postJson('/api/v1/support/requests', ['reason' => 'learner_requested'])
            ->json('data.id');

        SupportRequest::query()->findOrFail($id)->forceFill([
            'status' => SupportRequest::STATUS_RESOLVED,
            'resolved_at' => now(),
        ])->save();

        $this->postJson('/api/v1/support/requests/'.$id.'/messages', ['body' => 'one more thing'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SUPPORT_REQUEST_CLOSED');
    }

    public function test_the_handoff_countdown_is_published_to_the_client(): void
    {
        config(['services.support.handoff_seconds' => 7]);

        [$learner] = $this->learner();
        Sanctum::actingAs($learner);

        $this->postJson('/api/v1/support/requests', ['reason' => 'learner_requested'])
            ->assertStatus(201)
            ->assertJsonPath('data.handoff_seconds', 7);
    }

    // ------------------------------------------------------------- helpers

    /** @return array{0: User, 1: StudentProfile} */
    private function learner(): array
    {
        $user = $this->userWithRole($this->learnerRole);

        $role = CareerRole::forceCreate([
            'title' => 'Data Analyst',
            'slug' => 'data-analyst-'.uniqid(),
            'version' => 1,
            'status' => 'approved',
        ]);

        $profile = StudentProfile::forceCreate([
            'user_id' => $user->id,
            'availability' => 'full_time',
            'primary_career_role_id' => $role->id,
        ]);

        return [$user, $profile];
    }

    private function mentor(): User
    {
        $user = User::forceCreate([
            'name' => 'Mentor',
            'email' => uniqid().'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        // Mentor identity is a ProfessionalProfile attribute, not a role slug.
        ProfessionalProfile::create([
            'user_id' => $user->id,
            'type' => 'mentor',
            'expertise' => 'Backend engineering',
        ]);

        return $user->fresh();
    }

    private function userWithRole(Role $role): User
    {
        // `example.com`, never `test.com`: the `indisposable` rule makes
        // test.com addresses disposable, so an "expects 422" assertion would
        // pass for the wrong reason.
        $user = User::forceCreate([
            'name' => 'Support Tester',
            'email' => uniqid().'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function interaction(StudentProfile $profile): AssistantInteraction
    {
        return AssistantInteraction::create([
            'student_profile_id' => $profile->id,
            'intent' => 'explain_readiness',
            'context_reference' => 'ctx-'.uniqid(),
            'response_status' => AssistantInteraction::STATUS_SUCCEEDED,
            'answer_status' => 'insufficient_context',
            'grounded' => false,
            'algorithm_version' => 'v1',
            'prompt_version' => 'v1',
            'configuration_version' => 1,
            'request_id' => 'req-'.uniqid(),
        ]);
    }
}
