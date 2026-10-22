<?php

namespace Tests\Feature\Communication;

use App\Models\Conversation;
use App\Models\MentorStudentConnection;
use App\Models\Message;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Delete" in the chat list removes the conversation from the CALLER's list
 * only. It is deliberately not a delete: the thread carries an explicit notice
 * that communication is recorded for audit purposes, so erasing it for both
 * participants would destroy the other side's history and the audit trail.
 *
 * These tests pin the contract that makes that safe:
 *
 *   1. hiding removes it from the caller's list;
 *   2. the other participant is completely unaffected;
 *   3. a stranger gets 403 and cannot hide someone else's conversation;
 *   4. hiding twice is idempotent, not an error and not a duplicate row;
 *   5. a new message brings the conversation back — nothing can be lost
 *      silently, which was the original complaint.
 */
class ConversationHideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
    }

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

    /**
     * @return array{0: User, 1: User, 2: Conversation}
     */
    private function conversation(): array
    {
        $mentor = $this->mentor();
        $student = $this->student();

        $connection = MentorStudentConnection::forceCreate([
            'mentor_id' => $mentor->id,
            'student_id' => $student->id,
            'project_id' => null,
            'status' => 'active',
            'initiated_by' => 'mentor',
        ]);

        $conversation = Conversation::forceCreate([
            'mentor_student_connection_id' => $connection->id,
            'status' => 'active',
            'created_by' => $mentor->id,
            'retention_expires_at' => now()->addDays(365),
            'last_message_at' => now(),
        ]);

        Message::forceCreate([
            'conversation_id' => $conversation->id,
            'sender_id' => $mentor->id,
            'body' => 'Hello, how can I help?',
            'message_type' => 'text',
        ]);

        return [$mentor, $student, $conversation];
    }

    private function listIds(User $user): array
    {
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/conversations')->assertOk();

        return array_column($response->json('data'), 'id');
    }

    public function test_hiding_removes_the_conversation_from_the_callers_list(): void
    {
        [$mentor, , $conversation] = $this->conversation();

        $this->assertContains($conversation->id, $this->listIds($mentor));

        Sanctum::actingAs($mentor);
        $this->deleteJson("/api/v1/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotContains($conversation->id, $this->listIds($mentor));
    }

    public function test_the_other_participant_still_sees_the_conversation(): void
    {
        [$mentor, $student, $conversation] = $this->conversation();

        Sanctum::actingAs($mentor);
        $this->deleteJson("/api/v1/conversations/{$conversation->id}")->assertOk();

        $this->assertContains(
            $conversation->id,
            $this->listIds($student),
            'Hiding must be per-user — the student keeps their history.',
        );
    }

    public function test_the_messages_and_audit_records_are_untouched(): void
    {
        [$mentor, , $conversation] = $this->conversation();

        $messagesBefore = Message::where('conversation_id', $conversation->id)->count();

        Sanctum::actingAs($mentor);
        $this->deleteJson("/api/v1/conversations/{$conversation->id}")->assertOk();

        $this->assertSame(
            $messagesBefore,
            Message::where('conversation_id', $conversation->id)->count(),
            'Hiding must not remove messages — the thread is retained for audit.',
        );
        $this->assertNotNull(
            Conversation::find($conversation->id),
            'The conversation row itself must survive.',
        );
    }

    public function test_a_stranger_cannot_hide_someone_elses_conversation(): void
    {
        [, , $conversation] = $this->conversation();
        $stranger = $this->student();

        Sanctum::actingAs($stranger);

        $this->deleteJson("/api/v1/conversations/{$conversation->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'UNAUTHORIZED_CONVERSATION');

        $this->assertSame(
            0,
            DB::table('conversation_user_hides')->where('conversation_id', $conversation->id)->count(),
        );
    }

    public function test_hiding_twice_is_idempotent(): void
    {
        [$mentor, , $conversation] = $this->conversation();

        Sanctum::actingAs($mentor);
        $this->deleteJson("/api/v1/conversations/{$conversation->id}")->assertOk();
        $this->deleteJson("/api/v1/conversations/{$conversation->id}")->assertOk();

        $this->assertSame(
            1,
            DB::table('conversation_user_hides')->where('conversation_id', $conversation->id)->count(),
            'The unique key must keep a repeated hide from creating a second row.',
        );
    }

    public function test_a_new_message_brings_the_conversation_back(): void
    {
        [$mentor, $student, $conversation] = $this->conversation();

        Sanctum::actingAs($mentor);
        $this->deleteJson("/api/v1/conversations/{$conversation->id}")->assertOk();
        $this->assertNotContains($conversation->id, $this->listIds($mentor));

        // The student replies.
        Sanctum::actingAs($student);
        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'body' => 'Thanks, I have a question.',
            'message' => 'Thanks, I have a question.',
        ])->assertSuccessful();

        $this->assertContains(
            $conversation->id,
            $this->listIds($mentor),
            'A reply must make the conversation visible again for the mentor.',
        );
    }
}
