<?php

namespace Tests\Feature\Communication;

use App\Models\AuditEvent;
use App\Models\MentorStudentConnection;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\ProfessionalProfile;
use App\Models\Project;
use App\Models\ProjectEligibilityConstraint;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mentor–student communication system: mentor auth/authorization,
 * student visibility rules, project connection workflow, audit trail,
 * notification dispatch, and API security.
 */
class MentorStudentTest extends TestCase
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

    private function unverifiedMentor(): User
    {
        $user = User::forceCreate([
            'name' => 'Pending Mentor',
            'email' => 'pending_'.uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        ProfessionalProfile::forceCreate([
            'user_id' => $user->id,
            'type' => 'mentor',
            'verification_status' => 'pending',
        ]);

        return $user;
    }

    private function student(string $visibility = 'public'): User
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
            'visibility' => $visibility,
            'university_name' => 'King Saud University',
            'specialization' => 'Computer Science',
            'career_status' => 'Student',
            'preferred_work_type' => 'remote',
            'availability' => 'full_time',
            'interests' => ['AI', 'Web Development'],
        ]);

        return $user;
    }

    private function project(array $overrides = []): Project
    {
        $mentor = $this->mentor();

        return Project::forceCreate(array_merge([
            'owner_id' => $mentor->id,
            'type' => 'simulation',
            'title' => 'AI Chatbot Project',
            'status' => 'open',
            'capacity' => 5,
            'work_mode' => 'remote',
            'schedule' => 'flexible',
        ], $overrides));
    }

    private function connection(?User $mentor = null, ?User $student = null, ?Project $project = null, string $status = 'pending'): MentorStudentConnection
    {
        $mentor ??= $this->mentor();
        $student ??= $this->student();

        return MentorStudentConnection::forceCreate([
            'mentor_id' => $mentor->id,
            'student_id' => $student->id,
            'project_id' => $project?->id,
            'status' => $status,
            'initiated_by' => 'mentor',
        ]);
    }

    // ─── Mentor authentication ─────────────────────────────────

    public function test_mentor_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/mentor/students')->assertStatus(401);
        $this->getJson('/api/v1/mentor/students/1')->assertStatus(401);
        $this->postJson('/api/v1/mentor/connections', [])->assertStatus(401);
        $this->getJson('/api/v1/mentor/connections')->assertStatus(401);
        $this->patchJson('/api/v1/mentor/connections/1', ['status' => 'active'])->assertStatus(401);
    }

    public function test_conversation_endpoints_require_authentication(): void
    {
        $this->postJson('/api/v1/connections/1/conversations', [])->assertStatus(401);
        $this->getJson('/api/v1/conversations')->assertStatus(401);
        $this->getJson('/api/v1/conversations/1')->assertStatus(401);
        $this->postJson('/api/v1/conversations/1/messages', ['body' => 'hi'])->assertStatus(401);
        $this->getJson('/api/v1/conversations/1/messages')->assertStatus(401);
        $this->postJson('/api/v1/conversations/1/read')->assertStatus(401);
        $this->getJson('/api/v1/conversations/1/status')->assertStatus(401);
    }

    // ─── Mentor authorization ──────────────────────────────────

    public function test_non_mentor_users_are_forbidden_on_mentor_routes(): void
    {
        $learner = $this->student();

        $learner->roles()->attach(Role::where('slug', 'learner')->first()->id);
        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/mentor/students')->assertStatus(403);
        $this->postJson('/api/v1/mentor/connections', [])->assertStatus(403);
        $this->getJson('/api/v1/mentor/connections')->assertStatus(403);
    }

    public function test_unverified_mentor_is_forbidden(): void
    {
        Sanctum::actingAs($this->unverifiedMentor());

        $this->getJson('/api/v1/mentor/students')
            ->assertStatus(403)
            ->assertJsonPath('code', 'MENTOR_ONLY');
    }

    public function test_verified_mentor_can_access_student_list(): void
    {
        Sanctum::actingAs($this->mentor());

        $this->getJson('/api/v1/mentor/students')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_suspended_mentor_is_forbidden(): void
    {
        $mentor = $this->mentor();
        $mentor->forceFill(['status' => 'suspended'])->save();

        Sanctum::actingAs($mentor);

        $this->getJson('/api/v1/mentor/students')->assertStatus(403);
    }

    // ─── Student visibility ─────────────────────────────────────

    public function test_permitted_students_include_public_profiles(): void
    {
        $publicStudent = $this->student('public');

        Sanctum::actingAs($this->mentor());

        $response = $this->getJson('/api/v1/mentor/students')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($publicStudent->id));
    }

    public function test_permitted_students_include_connected_students(): void
    {
        $privateStudent = $this->student('private');
        $mentor = $this->mentor();

        // Create an active connection so the private student becomes visible.
        $this->connection($mentor, $privateStudent, null, 'active');

        Sanctum::actingAs($mentor);

        $response = $this->getJson('/api/v1/mentor/students')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($privateStudent->id));
    }

    public function test_permitted_students_exclude_private_profiles_without_connection(): void
    {
        $privateStudent = $this->student('private');

        Sanctum::actingAs($this->mentor());

        $response = $this->getJson('/api/v1/mentor/students')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($privateStudent->id));
    }

    public function test_permitted_students_include_organization_only_when_shared_org(): void
    {
        $org = Organization::forceCreate([
            'name' => 'Shared Company',
            'type' => 'company',
            'verification_status' => 'verified',
            'contact_email' => 'hr@shared.com',
        ]);

        $mentor = $this->mentor();
        $student = $this->student('organization_only');

        $org->members()->attach($mentor->id, ['role_in_org' => 'admin', 'status' => 'active']);
        $org->members()->attach($student->id, ['role_in_org' => 'member', 'status' => 'active']);

        Sanctum::actingAs($mentor);

        $response = $this->getJson('/api/v1/mentor/students')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($student->id));
    }

    public function test_permitted_students_exclude_organization_only_without_shared_org(): void
    {
        $orgA = Organization::forceCreate([
            'name' => 'Company A',
            'type' => 'company',
            'verification_status' => 'verified',
            'contact_email' => 'hr@a.com',
        ]);

        $orgB = Organization::forceCreate([
            'name' => 'Company B',
            'type' => 'company',
            'verification_status' => 'verified',
            'contact_email' => 'hr@b.com',
        ]);

        $mentor = $this->mentor();
        $student = $this->student('organization_only');

        // Mentor in org A, student in org B — no overlap.
        $orgA->members()->attach($mentor->id, ['role_in_org' => 'admin', 'status' => 'active']);
        $orgB->members()->attach($student->id, ['role_in_org' => 'member', 'status' => 'active']);

        Sanctum::actingAs($mentor);

        $response = $this->getJson('/api/v1/mentor/students')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($student->id));
    }

    // ─── Student summary / unauthorized student access ──────────

    public function test_student_summary_returns_data_for_visible_student(): void
    {
        $student = $this->student('public');
        $mentor = $this->mentor();

        Sanctum::actingAs($mentor);

        $response = $this->getJson("/api/v1/mentor/students/{$student->id}")
            ->assertStatus(200);

        $response
            ->assertJsonPath('data.id', $student->id)
            ->assertJsonPath('data.name', $student->name)
            ->assertJsonPath('data.specialization', 'Computer Science')
            ->assertJsonPath('data.visibility', 'public');
    }

    public function test_student_summary_returns_403_for_private_student_without_connection(): void
    {
        $privateStudent = $this->student('private');

        Sanctum::actingAs($this->mentor());

        $this->getJson("/api/v1/mentor/students/{$privateStudent->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'STUDENT_NOT_VISIBLE');
    }

    public function test_student_summary_returns_403_for_organization_only_without_shared_org(): void
    {
        $student = $this->student('organization_only');

        Sanctum::actingAs($this->mentor());

        $this->getJson("/api/v1/mentor/students/{$student->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'STUDENT_NOT_VISIBLE');
    }

    public function test_student_summary_for_nonexistent_student_returns_403(): void
    {
        Sanctum::actingAs($this->mentor());

        // user 999999 does not exist — canAccessStudent returns false.
        $this->getJson('/api/v1/mentor/students/999999')
            ->assertStatus(403)
            ->assertJsonPath('code', 'STUDENT_NOT_VISIBLE');
    }

    public function test_student_summary_for_user_without_student_profile_returns_422(): void
    {
        $user = User::forceCreate([
            'name' => 'No Profile User',
            'email' => 'noprofile_'.uniqid().'@test.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $mentor = $this->mentor();

        // Give a connection so canAccessStudent returns true, but
        // there's no student_profile row → STUDENT_PROFILE_NOT_FOUND.
        MentorStudentConnection::forceCreate([
            'mentor_id' => $mentor->id,
            'student_id' => $user->id,
            'project_id' => null,
            'status' => 'active',
            'initiated_by' => 'mentor',
        ]);

        Sanctum::actingAs($mentor);

        $this->getJson("/api/v1/mentor/students/{$user->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'STUDENT_PROFILE_NOT_FOUND');
    }

    // ─── Project connection — success path ─────────────────────

    public function test_connect_creates_connection_with_audit_and_notification(): void
    {
        $mentor = $this->mentor();
        $student = $this->student();

        Sanctum::actingAs($mentor);

        $response = $this->postJson('/api/v1/mentor/connections', [
            'student_id' => $student->id,
        ], ['X-Request-ID' => 'test-req-001']);

        $response->assertStatus(201)
            ->assertJsonPath('data.mentor_id', $mentor->id)
            ->assertJsonPath('data.student_id', $student->id)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('mentor_student_connections', [
            'mentor_id' => $mentor->id,
            'student_id' => $student->id,
            'status' => 'pending',
        ]);

        // Audit trail written.
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $mentor->id,
            'action' => 'mentor_student_connection.created',
            'entity_type' => 'MentorStudentConnection',
        ]);

        // Student notified.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->id,
            'category' => 'mentor_connection',
            'event_key' => 'mentor_connection:'.$response->json('data.id'),
        ]);
    }

    public function test_connect_with_project_validates_availability_and_creates_connection(): void
    {
        $mentor = $this->mentor();
        $student = $this->student();
        $project = $this->project();

        Sanctum::actingAs($mentor);

        $response = $this->postJson('/api/v1/mentor/connections', [
            'student_id' => $student->id,
            'project_id' => $project->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.project_id', $project->id);

        $this->assertDatabaseHas('mentor_student_connections', [
            'mentor_id' => $mentor->id,
            'student_id' => $student->id,
            'project_id' => $project->id,
        ]);
    }

    // ─── Project connection — invalid project access ────────────

    public function test_connect_with_nonexistent_project_returns_422_validation(): void
    {
        $student = $this->student();

        Sanctum::actingAs($this->mentor());

        // The StoreConnectionRequest's exists:projects,id rule catches
        // this before the service-level PROJECT_NOT_FOUND (404) is
        // reached — validation is the first line of defense.
        $this->postJson('/api/v1/mentor/connections', [
            'student_id' => $student->id,
            'project_id' => 999999,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);
    }

    public function test_connect_with_unavailable_project_returns_422(): void
    {
        $student = $this->student();
        $project = $this->project(['status' => 'closed']);

        Sanctum::actingAs($this->mentor());

        $this->postJson('/api/v1/mentor/connections', [
            'student_id' => $student->id,
            'project_id' => $project->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_NOT_AVAILABLE');
    }

    public function test_connect_with_passed_deadline_returns_422(): void
    {
        $student = $this->student();
        $project = $this->project([
            'application_deadline' => now()->subWeek()->toDateString(),
        ]);

        Sanctum::actingAs($this->mentor());

        $this->postJson('/api/v1/mentor/connections', [
            'student_id' => $student->id,
            'project_id' => $project->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_DEADLINE_PASSED');
    }

    public function test_connect_with_full_project_returns_422(): void
    {
        $student = $this->student();
        $project = $this->project(['capacity' => 1]);

        // Fill the one slot.
        $otherStudent = $this->student();
        $this->connection(null, $otherStudent, $project, 'active');

        Sanctum::actingAs($this->mentor());

        $this->postJson('/api/v1/mentor/connections', [
            'student_id' => $student->id,
            'project_id' => $project->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_CAPACITY_REACHED');
    }

    // ─── Project connection — ineligible project ───────────────

    public function test_connect_with_ineligible_work_mode_returns_422(): void
    {
        $student = $this->student(); // preferred_work_type = 'remote'
        $project = $this->project(['work_mode' => 'onsite']);

        ProjectEligibilityConstraint::forceCreate([
            'project_id' => $project->id,
            'constraint_type' => 'work_mode',
            'value' => 'onsite',
        ]);

        Sanctum::actingAs($this->mentor());

        $this->postJson('/api/v1/mentor/connections', [
            'student_id' => $student->id,
            'project_id' => $project->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_ELIGIBILITY_FAILED')
            ->assertJsonPath('details.violations.0', fn ($v) => str_contains($v, 'Work mode mismatch'));
    }

    public function test_connect_with_ineligible_schedule_returns_422(): void
    {
        $student = $this->student(); // availability = 'full_time'
        $project = $this->project(['schedule' => 'part_time']);

        ProjectEligibilityConstraint::forceCreate([
            'project_id' => $project->id,
            'constraint_type' => 'schedule',
            'value' => 'part_time',
        ]);

        Sanctum::actingAs($this->mentor());

        $this->postJson('/api/v1/mentor/connections', [
            'student_id' => $student->id,
            'project_id' => $project->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_ELIGIBILITY_FAILED');
    }

    // ─── Duplicate / invalid connection attempts ────────────────

    public function test_duplicate_connection_without_project_returns_422(): void
    {
        $mentor = $this->mentor();
        $student = $this->student();

        $this->connection($mentor, $student, null, 'pending');

        Sanctum::actingAs($mentor);

        $this->postJson('/api/v1/mentor/connections', [
            'student_id' => $student->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DUPLICATE_CONNECTION');
    }

    public function test_duplicate_connection_with_project_returns_422(): void
    {
        $mentor = $this->mentor();
        $student = $this->student();
        $project = $this->project();

        $this->connection($mentor, $student, $project, 'pending');

        Sanctum::actingAs($mentor);

        $this->postJson('/api/v1/mentor/connections', [
            'student_id' => $student->id,
            'project_id' => $project->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DUPLICATE_CONNECTION');
    }

    public function test_connect_with_nonexistent_student_returns_422(): void
    {
        Sanctum::actingAs($this->mentor());

        // 999999 is a valid integer but no user exists → validation fails (exists:users,id).
        $this->postJson('/api/v1/mentor/connections', [
            'student_id' => 999999,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['student_id']);
    }

    // ─── Connection status updates ──────────────────────────────

    public function test_update_connection_status_to_active(): void
    {
        $mentor = $this->mentor();
        $student = $this->student();
        $conn = $this->connection($mentor, $student, null, 'pending');

        Sanctum::actingAs($mentor);

        $this->patchJson("/api/v1/mentor/connections/{$conn->id}", [
            'status' => 'active',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('mentor_student_connections', [
            'id' => $conn->id,
            'status' => 'active',
        ]);
    }

    public function test_disconnect_connection_records_reason_and_notifies(): void
    {
        $mentor = $this->mentor();
        $student = $this->student();
        $conn = $this->connection($mentor, $student, null, 'active');

        Sanctum::actingAs($mentor);

        $this->patchJson("/api/v1/mentor/connections/{$conn->id}", [
            'status' => 'disconnected',
            'reason' => 'Project completed',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'disconnected');

        $this->assertDatabaseHas('mentor_student_connections', [
            'id' => $conn->id,
            'status' => 'disconnected',
            'disconnected_reason' => 'Project completed',
        ]);

        // Student notified about the disconnection.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->id,
            'event_key' => "mentor_connection_disconnected:{$conn->id}",
        ]);

        // Audit trail.
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $mentor->id,
            'action' => 'mentor_student_connection.disconnected',
            'entity_id' => $conn->id,
        ]);
    }

    public function test_update_connection_validates_status_value(): void
    {
        $conn = $this->connection();

        Sanctum::actingAs(User::find($conn->mentor_id));

        $this->patchJson("/api/v1/mentor/connections/{$conn->id}", [
            'status' => 'invalid_status',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_another_mentor_cannot_update_someone_elses_connection(): void
    {
        // IDOR regression: the id in the URL used to be the only thing standing
        // between a verified mentor and another mentor's connection, so any
        // mentor could disconnect someone else's student.
        $owner = $this->mentor();
        $intruder = $this->mentor();
        $student = $this->student();
        $conn = $this->connection($owner, $student, null, 'pending');

        Sanctum::actingAs($intruder);

        $this->patchJson("/api/v1/mentor/connections/{$conn->id}", [
            'status' => 'disconnected',
            'reason' => 'Not mine to touch',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'CONNECTION_NOT_PARTICIPANT');

        // The row is untouched…
        $this->assertDatabaseHas('mentor_student_connections', [
            'id' => $conn->id,
            'status' => 'pending',
            'disconnected_reason' => null,
        ]);

        // …nothing was audited under the intruder's id…
        $this->assertDatabaseMissing('audit_events', [
            'actor_id' => $intruder->id,
            'entity_id' => $conn->id,
        ]);

        // …and the owner's student was never notified.
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $student->id,
            'event_key' => "mentor_connection_disconnected:{$conn->id}",
        ]);
    }

    public function test_a_non_participant_cannot_archive_an_unrelated_connection(): void
    {
        // Same guard, a different transition: the check must not live in the
        // disconnect branch alone.
        $conn = $this->connection(null, null, null, 'active');
        $intruder = $this->mentor();

        Sanctum::actingAs($intruder);

        $this->patchJson("/api/v1/mentor/connections/{$conn->id}", ['status' => 'archived'])
            ->assertStatus(403);

        $this->assertDatabaseHas('mentor_student_connections', [
            'id' => $conn->id,
            'status' => 'active',
        ]);
    }

    // ─── Notification behavior ──────────────────────────────────

    public function test_connection_creation_notifies_student(): void
    {
        $mentor = $this->mentor();
        $student = $this->student();

        Sanctum::actingAs($mentor);

        $response = $this->postJson('/api/v1/mentor/connections', [
            'student_id' => $student->id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->id,
            'category' => 'mentor_connection',
            'channel' => 'in_app',
            'event_key' => 'mentor_connection:'.$response->json('data.id'),
            'read_at' => null,
        ]);
    }

    public function test_connection_activation_notifies_student(): void
    {
        $mentor = $this->mentor();
        $student = $this->student();
        $conn = $this->connection($mentor, $student, null, 'pending');

        // Mentor activates the connection (the endpoint is behind the
        // 'mentor' middleware so only mentors can update status).
        Sanctum::actingAs($mentor);

        $this->patchJson("/api/v1/mentor/connections/{$conn->id}", [
            'status' => 'active',
        ])->assertStatus(200);

        // Student notified about the activation.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->id,
            'event_key' => "mentor_connection_active:{$conn->id}",
        ]);
    }

    // ─── Audit behavior ─────────────────────────────────────────

    public function test_connection_creation_writes_audit_with_before_after(): void
    {
        $mentor = $this->mentor();
        $student = $this->student();

        Sanctum::actingAs($mentor);

        $response = $this->postJson('/api/v1/mentor/connections', [
            'student_id' => $student->id,
        ], ['X-Request-ID' => 'audit-test-req']);

        $audit = AuditEvent::where('action', 'mentor_student_connection.created')
            ->where('entity_id', $response->json('data.id'))
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($mentor->id, $audit->actor_id);
        $this->assertSame('MentorStudentConnection', $audit->entity_type);
        $this->assertSame('audit-test-req', $audit->request_id);
        $this->assertNull($audit->before);
        $this->assertNotNull($audit->after);
        $this->assertNotNull($audit->occurred_at);
    }

    public function test_connection_status_change_writes_audit_with_before_after(): void
    {
        $mentor = $this->mentor();
        $student = $this->student();
        $conn = $this->connection($mentor, $student, null, 'pending');

        Sanctum::actingAs($mentor);

        $this->patchJson("/api/v1/mentor/connections/{$conn->id}", [
            'status' => 'active',
        ], ['X-Request-ID' => 'status-audit-req']);

        $audit = AuditEvent::where('action', 'mentor_student_connection.active')
            ->where('entity_id', $conn->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($mentor->id, $audit->actor_id);
        $this->assertSame('pending', $audit->before['status']);
        $this->assertSame('active', $audit->after['status']);
        $this->assertSame('status-audit-req', $audit->request_id);
    }

    // ─── API security ──────────────────────────────────────────

    public function test_x_request_id_is_propagated_in_response_header(): void
    {
        Sanctum::actingAs($this->mentor());

        $response = $this->getJson('/api/v1/mentor/students', ['X-Request-ID' => 'my-request-id']);

        $response->assertStatus(200);
        $this->assertSame('my-request-id', $response->headers->get('X-Request-ID'));
    }

    public function test_error_responses_include_request_id(): void
    {
        Sanctum::actingAs($this->mentor());

        $response = $this->getJson('/api/v1/mentor/students/999999', ['X-Request-ID' => 'err-req-123']);

        $response->assertStatus(403);
        $this->assertSame('err-req-123', $response->headers->get('X-Request-ID'));
        $this->assertSame('err-req-123', $response->json('request_id'));
    }

    // ─── Connections listing ────────────────────────────────────

    public function test_connections_list_returns_paginated_results(): void
    {
        $mentor = $this->mentor();

        $this->connection($mentor, $this->student(), null, 'active');
        $this->connection($mentor, $this->student(), null, 'pending');

        Sanctum::actingAs($mentor);

        $response = $this->getJson('/api/v1/mentor/connections')
            ->assertStatus(200);

        $response
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_connections_list_only_returns_own_connections(): void
    {
        $mentorA = $this->mentor();
        $mentorB = $this->mentor();

        $this->connection($mentorA, $this->student(), null, 'active');
        $this->connection($mentorB, $this->student(), null, 'active');

        Sanctum::actingAs($mentorA);

        $response = $this->getJson('/api/v1/mentor/connections')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($mentorA->id, $response->json('data.0.mentor_id'));
    }
}
