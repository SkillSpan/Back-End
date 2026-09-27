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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Chatbot communication endpoints: posting automated messages into a
 * mentor-student conversation, retrieving them in isolation from human
 * traffic, participant authorization, audit trail, and the guarantee
 * that a client cannot spoof a human message type through the chatbot
 * endpoint.
 */
class ChatbotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
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
        ]);

        return $user;
    }

    private function conversation(): array
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

        $conv = Conversation::forceCreate([
            'mentor_student_connection_id' => $conn->id,
            'status' => 'active',
            'created_by' => $mentor->id,
            'retention_expires_at' => now()->addDays(365),
        ]);

        return [$mentor, $student, $conn, $conv];
    }

    // ─── Authentication ────────────────────────────────────────

    public function test_chatbot_endpoints_require_authentication(): void
    {
        [, , , $conv] = $this->conversation();

        $this->postJson("/api/v1/conversations/{$conv->id}/chatbot/messages", ['body' => 'hi'])
            ->assertStatus(401);
        $this->getJson("/api/v1/conversations/{$conv->id}/chatbot/messages")
            ->assertStatus(401);
    }

    // ─── Posting ───────────────────────────────────────────────

    public function test_participant_can_post_a_chatbot_message(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        $response = $this->postJson("/api/v1/conversations/{$conv->id}/chatbot/messages", [
            'body' => 'Reminder: your weekly check-in is due.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.body', 'Reminder: your weekly check-in is due.')
            ->assertJsonPath('data.message_type', 'chatbot');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conv->id,
            'sender_id' => $mentor->id,
            'message_type' => 'chatbot',
        ]);
    }

    public function test_student_participant_can_post_a_chatbot_message(): void
    {
        [, $student, , $conv] = $this->conversation();

        Sanctum::actingAs($student);

        $this->postJson("/api/v1/conversations/{$conv->id}/chatbot/messages", [
            'body' => 'Automated question from the assistant.',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.message_type', 'chatbot');
    }

    public function test_client_cannot_spoof_message_type(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        // Even if the client sends message_type=text, the endpoint forces
        // 'chatbot' — the field is not in the request rules.
        $this->postJson("/api/v1/conversations/{$conv->id}/chatbot/messages", [
            'body' => 'Trying to look human.',
            'message_type' => 'text',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.message_type', 'chatbot');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conv->id,
            'message_type' => 'chatbot',
        ]);
    }

    public function test_chatbot_message_records_source_metadata(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        $this->postJson("/api/v1/conversations/{$conv->id}/chatbot/messages", [
            'body' => 'Guided next step.',
            'source' => 'onboarding_bot',
        ])->assertStatus(201);

        $message = Message::where('conversation_id', $conv->id)->first();
        $this->assertSame('onboarding_bot', $message->metadata['source']);
    }

    public function test_non_participant_cannot_post_a_chatbot_message(): void
    {
        [, , , $conv] = $this->conversation();
        $outsider = $this->student();

        Sanctum::actingAs($outsider);

        $this->postJson("/api/v1/conversations/{$conv->id}/chatbot/messages", [
            'body' => 'I should not be here.',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'UNAUTHORIZED_CONVERSATION');
    }

    public function test_cannot_post_to_archived_conversation(): void
    {
        [$mentor, , , $conv] = $this->conversation();
        $conv->forceFill(['status' => 'archived'])->save();

        Sanctum::actingAs($mentor);

        $this->postJson("/api/v1/conversations/{$conv->id}/chatbot/messages", [
            'body' => 'Too late.',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CONVERSATION_NOT_ACTIVE');
    }

    public function test_chatbot_message_requires_body(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        $this->postJson("/api/v1/conversations/{$conv->id}/chatbot/messages", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_chatbot_message_too_long_is_rejected(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        $this->postJson("/api/v1/conversations/{$conv->id}/chatbot/messages", [
            'body' => str_repeat('x', 5001),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    // ─── Audit + notification ──────────────────────────────────

    public function test_chatbot_message_writes_distinct_audit_action(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        $response = $this->postJson(
            "/api/v1/conversations/{$conv->id}/chatbot/messages",
            ['body' => 'Audited bot message'],
            ['X-Request-ID' => 'bot-audit-req'],
        );

        $audit = AuditEvent::where('action', 'chatbot.message.sent')
            ->where('entity_id', $response->json('data.id'))
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($mentor->id, $audit->actor_id);
        $this->assertSame('bot-audit-req', $audit->request_id);
        $this->assertSame('chatbot', $audit->after['type']);
    }

    public function test_chatbot_message_notifies_the_other_participant(): void
    {
        [$mentor, $student, , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        $response = $this->postJson("/api/v1/conversations/{$conv->id}/chatbot/messages", [
            'body' => 'Automated reminder.',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->id,
            'category' => 'message',
            'event_key' => 'message:'.$response->json('data.id'),
        ]);
    }

    public function test_chatbot_message_updates_last_message_at(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        $this->postJson("/api/v1/conversations/{$conv->id}/chatbot/messages", [
            'body' => 'Timestamp test',
        ]);

        $this->assertNotNull($conv->fresh()->last_message_at);
    }

    // ─── Retrieval ─────────────────────────────────────────────

    public function test_retrieval_returns_only_chatbot_messages(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        // One human message and one chatbot message.
        Message::forceCreate([
            'conversation_id' => $conv->id,
            'sender_id' => $mentor->id,
            'body' => 'Human message',
            'message_type' => 'text',
        ]);
        Message::forceCreate([
            'conversation_id' => $conv->id,
            'sender_id' => $mentor->id,
            'body' => 'Bot message',
            'message_type' => 'chatbot',
        ]);

        Sanctum::actingAs($mentor);

        $response = $this->getJson("/api/v1/conversations/{$conv->id}/chatbot/messages")
            ->assertStatus(200);

        $response->assertJsonPath('meta.total', 1);
        $this->assertSame('Bot message', $response->json('data.0.body'));
        $this->assertSame('chatbot', $response->json('data.0.message_type'));
    }

    public function test_retrieval_by_non_participant_returns_403(): void
    {
        [, , , $conv] = $this->conversation();
        $outsider = $this->student();

        Sanctum::actingAs($outsider);

        $this->getJson("/api/v1/conversations/{$conv->id}/chatbot/messages")
            ->assertStatus(403)
            ->assertJsonPath('code', 'UNAUTHORIZED_CONVERSATION');
    }

    public function test_retrieval_is_empty_when_no_chatbot_messages(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        $this->getJson("/api/v1/conversations/{$conv->id}/chatbot/messages")
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 0);
    }

    // ─── API security ──────────────────────────────────────────

    public function test_x_request_id_propagated_on_chatbot_post(): void
    {
        [$mentor, , , $conv] = $this->conversation();

        Sanctum::actingAs($mentor);

        $response = $this->postJson(
            "/api/v1/conversations/{$conv->id}/chatbot/messages",
            ['body' => 'Correlation test'],
            ['X-Request-ID' => 'bot-req-1'],
        );

        $response->assertStatus(201);
        $this->assertSame('bot-req-1', $response->headers->get('X-Request-ID'));
    }

    public function test_error_response_includes_request_id(): void
    {
        [, , , $conv] = $this->conversation();
        $outsider = $this->student();

        Sanctum::actingAs($outsider);

        $response = $this->postJson(
            "/api/v1/conversations/{$conv->id}/chatbot/messages",
            ['body' => 'Nope'],
            ['X-Request-ID' => 'bot-err-1'],
        );

        $response->assertStatus(403);
        $this->assertSame('bot-err-1', $response->headers->get('X-Request-ID'));
        $this->assertSame('bot-err-1', $response->json('request_id'));
    }
}
