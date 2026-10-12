<?php

namespace Tests\Feature\Communication;

use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\MentorStudentConnection;
use App\Models\Message;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Conversation and messaging tests: conversation creation/access,
 * message submission/retrieval, chat authorization, communication
 * status, privacy behavior, and audit trail.
 */
class ConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
    }

    // ─── Helpers ───────────────────────────────────────────────

    private function mentor(): User
    {
        $user = User::forceCreate([
            'name' => 'Dr. Mentor',
            'email' => 'mentor_'.uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        ProfessionalProfile::forceCreate([
            'user_id' => $user->id,
            'type' => 'mentor',
            'verification_status' => 'verified',
            'expertise' => 'Software Engineering',
        ]);

        return $user;
    }

    private function student(): User
    {
        $user = User::forceCreate([
            'name' => 'Student '.uniqid(),
            'email' => 'student_'.uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', 'learner')->first()->id);

        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'visibility' => 'public',
            'university_name' => 'King Saud University',
            'specialization' => 'Computer Science',
            'career_status' => 'Student',
            'preferred_work_type' => 'remote',
            'availability' => 'full_time',
        ]);

        return $user;
    }

    private function activeConnection(): array
    {
        $mentor = $this->mentor();
        $student = $this->student();

        $conn = MentorStudentConnection::forceCreate([
            'mentor_id' => $mentor->id,
            'student_id' => $student->id,
            'project_id' => null,
            'status' => 'active',
            'initiated_by' => 'mentor',
        ]);

        return [$mentor, $student, $conn];
    }

    private function conversation(): array
    {
        [$mentor, $student, $conn] = $this->activeConnection();

        $conv = Conversation::forceCreate([
            'mentor_student_connection_id' => $conn->id,
            'status' => 'active',
            'created_by' => $mentor->id,
            'retention_expires_at' => now()->addDays(365),
        ]);

        return [$mentor, $student, $conn, $conv];
    }

    // ─── Conversation creation ──────────────────────────────────

    public function test_create_conversation_returns_201(): void
    {
        [$mentor, $student, $conn] = $this->activeConnection();

        Sanctum::actingAs($mentor);

        $response = $this->postJson("/api/v1/connections/{$conn->id}/conversations", []);

        $response->assertStatus(201)
            ->assertJsonPath('data.mentor_student_connection_id', $conn->id)
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('conversations', [
            'mentor_student_connection_id' => $conn->id,
            'status' => 'active',
            'created_by' => $mentor->id,
        ]);
    }

    public function test_create_conversation_is_idempotent(): void
    {
        [$mentor, , $conn] = $this->activeConnection();

        Sanctum::actingAs($mentor);

        $first = $this->postJson("/api/v1/connections/{$conn->id}/conversations", []);
        $first->assertStatus(201);

        $second = $this->postJson("/api/v1/connections/{$conn->id}/conversations", []);
        $second->assertStatus(201);

        // Same conversation returned, not a duplicate.
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Conversation::where('mentor_student_connection_id', $conn->id)->count());
    }

    public function test_create_conversation_by_student_participant(): void
    {
        [, $student, $conn] = $this->activeConnection();

        Sanctum::actingAs($student);

        $response = $this->postJson("/api/v1/connections/{$conn->id}/conversations", []);

        $response->assertStatus(201)
            ->assertJsonPath('data.created_by', $student->id);
    }

    public function test_create_conversation_by_non_participant_returns_403(): void
    {
        [, , $conn] = $this->activeConnection();
        $outsider = $this->student();

        Sanctum::actingAs($outsider);

        $this->postJson("/api/v1/connections/{$conn->id}/conversations", [])
            ->assertStatus(403)
            ->assertJsonPath('code', 'NOT_A_PARTICIPANT');
    }

    public function test_create_conversation_on_disconnected_connection_returns_422(): void
    {
        $mentor = $this->mentor();
        $student = $this->student();

        $conn = MentorStudentConnection::forceCreate([
            'mentor_id' => $mentor->id,
            'student_id' => $student->id,
            'project_id' => null,
            'status' => 'disconnected',
            'initiated_by' => 'mentor',
            'disconnected_at' => now(),
        ]);

        Sanctum::actingAs($mentor);

        $this->postJson("/api/v1/connections/{$conn->id}/conversations", [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CONNECTION_NOT_ACTIVE');
    }

    public function test_conversation_creation_writes_audit(): void
    {
        [$mentor, , $conn] = $this->activeConnection();

        Sanctum::actingAs($mentor);

        $response = $this->postJson("/api/v1/connections/{$conn->id}/conversations", [], ['X-Request-ID' => 'conv-audit-req']);

        $audit = AuditEvent::where('action', 'conversation.created')
            ->where('entity_id', $response->json('data.id'))
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($mentor->id, $audit->actor_id);
        $this->assertSame('conv-audit-req', $audit->request_id);
    }

    public function test_conversation_has_retention_expiry(): void
    {
        [$mentor, , $conn] = $this->activeConnection();

        Sanctum::actingAs($mentor);

        $response = $this->postJson("/api/v1/connections/{$conn->id}/conversations", []);

        $this->assertNotNull($response->json('data.retention_expires_at'));
    }

    // ─── Chat authorization (unauthorized conversation access) ──

    public function test_show_conversation_by_non_participant_returns_403(): void
    {
        [, , , $conv] = $this->conversation();
        $outsider = $this->student();

        Sanctum::actingAs($outsider);

        $this->getJson("/api/v1/conversations/{$conv->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'UNAUTHORIZED_CONVERSATION');
    }

    public function test_show_conversation_by_mentor_participant_returns_200(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        $this->getJson("/api/v1/conversations/{$conv->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $conv->id);
    }

    public function test_show_conversation_by_student_participant_returns_200(): void
    {
        [, $student, , $conv] = $this->conversation();

        Sanctum::actingAs($student);

        $this->getJson("/api/v1/conversations/{$conv->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $conv->id);
    }

    // ─── Message sending ────────────────────────────────────────

    public function test_send_message_returns_201(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        $response = $this->postJson("/api/v1/conversations/{$conv->id}/messages", [
            'body' => 'Hello, welcome to the project!',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.body', 'Hello, welcome to the project!')
            ->assertJsonPath('data.sender_id', $mentor->id)
            ->assertJsonPath('data.message_type', 'text');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conv->id,
            'sender_id' => $mentor->id,
            'body' => 'Hello, welcome to the project!',
        ]);
    }

    public function test_send_message_by_student_returns_201(): void
    {
        [, $student, , $conv] = $this->conversation();

        Sanctum::actingAs($student);

        $response = $this->postJson("/api/v1/conversations/{$conv->id}/messages", [
            'body' => 'Thank you, I am excited to start!',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.sender_id', $student->id);
    }

    public function test_send_message_by_non_participant_returns_403(): void
    {
        [, , , $conv] = $this->conversation();
        $outsider = $this->student();

        Sanctum::actingAs($outsider);

        $this->postJson("/api/v1/conversations/{$conv->id}/messages", [
            'body' => 'I should not be here.',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'UNAUTHORIZED_CONVERSATION');
    }

    public function test_send_message_on_archived_conversation_returns_422(): void
    {
        [$mentor, , , $conv] = $this->conversation();
        $conv->forceFill(['status' => 'archived'])->save();

        Sanctum::actingAs($mentor);

        $this->postJson("/api/v1/conversations/{$conv->id}/messages", [
            'body' => 'Hello?',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CONVERSATION_NOT_ACTIVE');
    }

    public function test_send_message_too_long_returns_422(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        // The StoreMessageRequest form request has a max:5000 rule that
        // catches the oversize body before the service-level
        // MESSAGE_TOO_LONG check is reached.
        $this->postJson("/api/v1/conversations/{$conv->id}/messages", [
            'body' => str_repeat('x', 5001),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_send_message_with_chatbot_type(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        $response = $this->postJson("/api/v1/conversations/{$conv->id}/messages", [
            'body' => 'Automated reminder: submit your report.',
            'message_type' => 'chatbot',
            'metadata' => ['trigger' => 'scheduled_reminder'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.message_type', 'chatbot');
    }

    public function test_a_participant_cannot_forge_a_system_message(): void
    {
        // `system` marks a message as platform-authored. Nothing server-side
        // ever writes one into `messages` (that type belongs to
        // support_messages), so accepting it from the client had no legitimate
        // caller — and let a participant put a fake platform notice in the
        // other party's inbox.
        [$mentor, , , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        $this->postJson("/api/v1/conversations/{$conv->id}/messages", [
            'body' => 'Your account has been suspended by an administrator.',
            'message_type' => 'system',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message_type']);

        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conv->id,
            'message_type' => 'system',
        ]);
    }

    public function test_omitted_and_explicit_text_types_are_both_stored_as_text(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        $this->postJson("/api/v1/conversations/{$conv->id}/messages", [
            'body' => 'No type sent.',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.message_type', 'text');

        $this->postJson("/api/v1/conversations/{$conv->id}/messages", [
            'body' => 'Explicit text.',
            'message_type' => 'text',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.message_type', 'text');
    }

    public function test_send_message_writes_audit_and_notifies_recipient(): void
    {
        [$mentor, $student, , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        $response = $this->postJson("/api/v1/conversations/{$conv->id}/messages", [
            'body' => 'Audit test message',
        ], ['X-Request-ID' => 'msg-audit-req']);

        // Audit trail.
        $audit = AuditEvent::where('action', 'message.sent')
            ->where('entity_id', $response->json('data.id'))
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($mentor->id, $audit->actor_id);
        $this->assertSame('msg-audit-req', $audit->request_id);

        // Recipient (student) notified.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->id,
            'category' => 'message',
            'event_key' => 'message:'.$response->json('data.id'),
        ]);
    }

    public function test_send_message_updates_last_message_at(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        $this->postJson("/api/v1/conversations/{$conv->id}/messages", [
            'body' => 'Time stamp test',
        ]);

        $conv->refresh();
        $this->assertNotNull($conv->last_message_at);
    }

    // ─── Message retrieval ──────────────────────────────────────

    public function test_message_retrieval_returns_paginated_results(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        Message::forceCreate(['conversation_id' => $conv->id, 'sender_id' => $mentor->id, 'body' => 'First message']);
        Message::forceCreate(['conversation_id' => $conv->id, 'sender_id' => $mentor->id, 'body' => 'Second message']);

        Sanctum::actingAs($mentor);

        $response = $this->getJson("/api/v1/conversations/{$conv->id}/messages")
            ->assertStatus(200);

        $response
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 2);

        // Latest first.
        $this->assertSame('Second message', $response->json('data.0.body'));
    }

    public function test_message_retrieval_by_non_participant_returns_403(): void
    {
        [, , , $conv] = $this->conversation();
        $outsider = $this->student();

        Sanctum::actingAs($outsider);

        $this->getJson("/api/v1/conversations/{$conv->id}/messages")
            ->assertStatus(403)
            ->assertJsonPath('code', 'UNAUTHORIZED_CONVERSATION');
    }

    // ─── Mark as read ───────────────────────────────────────────

    public function test_mark_as_read_updates_unread_messages(): void
    {
        [$mentor, $student, , $conv] = $this->conversation();

        // Mentor sends two messages; student has 2 unread.
        Message::forceCreate(['conversation_id' => $conv->id, 'sender_id' => $mentor->id, 'body' => 'Msg 1']);
        Message::forceCreate(['conversation_id' => $conv->id, 'sender_id' => $mentor->id, 'body' => 'Msg 2']);

        Sanctum::actingAs($student);

        $response = $this->postJson("/api/v1/conversations/{$conv->id}/read")
            ->assertStatus(200)
            ->assertJsonPath('data.marked_read', 2);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conv->id,
            'sender_id' => $mentor->id,
            'body' => 'Msg 1',
            'read_by' => $student->id,
        ]);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conv->id,
            'sender_id' => $mentor->id,
            'body' => 'Msg 2',
            'read_by' => $student->id,
        ]);
    }

    public function test_mark_as_read_only_marks_other_senders_messages(): void
    {
        [$mentor, $student, , $conv] = $this->conversation();

        // Student sends 1, mentor sends 1. Student calls markAsRead —
        // only the mentor's message should be marked.
        Message::forceCreate(['conversation_id' => $conv->id, 'sender_id' => $student->id, 'body' => 'Student msg']);
        Message::forceCreate(['conversation_id' => $conv->id, 'sender_id' => $mentor->id, 'body' => 'Mentor msg']);

        Sanctum::actingAs($student);

        $response = $this->postJson("/api/v1/conversations/{$conv->id}/read");

        $response->assertJsonPath('data.marked_read', 1);
    }

    public function test_mark_as_read_by_non_participant_returns_403(): void
    {
        [, , , $conv] = $this->conversation();
        $outsider = $this->student();

        Sanctum::actingAs($outsider);

        $this->postJson("/api/v1/conversations/{$conv->id}/read")
            ->assertStatus(403)
            ->assertJsonPath('code', 'UNAUTHORIZED_CONVERSATION');
    }

    // ─── Communication status ───────────────────────────────────

    public function test_communication_status_returns_unread_count(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        Message::forceCreate(['conversation_id' => $conv->id, 'sender_id' => $mentor->id, 'body' => 'Unread 1']);
        Message::forceCreate(['conversation_id' => $conv->id, 'sender_id' => $mentor->id, 'body' => 'Unread 2']);

        Sanctum::actingAs($mentor);

        // Mentor sent them, so mentor has 0 unread (own messages excluded).
        $response = $this->getJson("/api/v1/conversations/{$conv->id}/status")
            ->assertStatus(200);

        $response->assertJsonPath('data.conversation_id', $conv->id)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_communication_status_for_student_shows_unread(): void
    {
        [$mentor, $student, , $conv] = $this->conversation();

        Message::forceCreate(['conversation_id' => $conv->id, 'sender_id' => $mentor->id, 'body' => 'Hello']);

        Sanctum::actingAs($student);

        $response = $this->getJson("/api/v1/conversations/{$conv->id}/status")
            ->assertStatus(200);

        $response
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.last_message_preview', 'Hello');
    }

    public function test_communication_status_by_non_participant_returns_403(): void
    {
        [, , , $conv] = $this->conversation();
        $outsider = $this->student();

        Sanctum::actingAs($outsider);

        $this->getJson("/api/v1/conversations/{$conv->id}/status")
            ->assertStatus(403)
            ->assertJsonPath('code', 'UNAUTHORIZED_CONVERSATION');
    }

    // ─── Conversations listing ──────────────────────────────────

    public function test_conversations_index_returns_user_conversations(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        $response = $this->getJson('/api/v1/conversations')
            ->assertStatus(200);

        $response
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_conversations_index_excludes_other_users(): void
    {
        [$mentor, , , $conv] = $this->conversation();
        $otherMentor = $this->mentor();
        $otherStudent = $this->student();

        $otherConn = MentorStudentConnection::forceCreate([
            'mentor_id' => $otherMentor->id,
            'student_id' => $otherStudent->id,
            'project_id' => null,
            'status' => 'active',
            'initiated_by' => 'mentor',
        ]);

        Conversation::forceCreate([
            'mentor_student_connection_id' => $otherConn->id,
            'status' => 'active',
            'created_by' => $otherMentor->id,
            'retention_expires_at' => now()->addDays(365),
        ]);

        Sanctum::actingAs($mentor);

        $response = $this->getJson('/api/v1/conversations')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($conv->id, $response->json('data.0.id'));
    }

    // ─── Privacy behavior ───────────────────────────────────────

    public function test_private_student_messages_not_accessible_after_disconnect(): void
    {
        [$mentor, $student, $conn, $conv] = $this->conversation();

        // Disconnect the connection.
        $conn->forceFill(['status' => 'disconnected', 'disconnected_at' => now()])->save();

        // Archive the conversation.
        $conv->forceFill(['status' => 'archived'])->save();

        Sanctum::actingAs($mentor);

        // Cannot send new messages to an archived conversation.
        $this->postJson("/api/v1/conversations/{$conv->id}/messages", [
            'body' => 'After disconnect',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CONVERSATION_NOT_ACTIVE');
    }

    public function test_retention_expiry_is_set_on_creation(): void
    {
        [$mentor, , $conn] = $this->activeConnection();

        Sanctum::actingAs($mentor);

        $response = $this->postJson("/api/v1/connections/{$conn->id}/conversations", []);

        $expiresAt = $response->json('data.retention_expires_at');
        $this->assertNotNull($expiresAt);
        $this->assertTrue(now()->addDays(364)->lt(Carbon::parse($expiresAt)));
    }

    // ─── API security ──────────────────────────────────────────

    public function test_x_request_id_propagated_in_conversation_responses(): void
    {
        [$mentor, , $conn] = $this->activeConnection();

        Sanctum::actingAs($mentor);

        $response = $this->postJson("/api/v1/connections/{$conn->id}/conversations", [], ['X-Request-ID' => 'conv-sec-req']);

        $response->assertStatus(201);
        $this->assertSame('conv-sec-req', $response->headers->get('X-Request-ID'));
    }

    public function test_error_responses_include_request_id_for_conversations(): void
    {
        [, , , $conv] = $this->conversation();
        $outsider = $this->student();

        Sanctum::actingAs($outsider);

        $response = $this->getJson("/api/v1/conversations/{$conv->id}", ['X-Request-ID' => 'conv-err-req']);

        $response->assertStatus(403);
        $this->assertSame('conv-err-req', $response->headers->get('X-Request-ID'));
        $this->assertSame('conv-err-req', $response->json('request_id'));
    }

    public function test_message_body_is_required(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        $this->postJson("/api/v1/conversations/{$conv->id}/messages", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_invalid_message_type_rejected(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        $this->postJson("/api/v1/conversations/{$conv->id}/messages", [
            'body' => 'Test',
            'message_type' => 'invalid_type',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message_type']);
    }
}
