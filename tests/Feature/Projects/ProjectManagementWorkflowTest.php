<?php

namespace Tests\Feature\Projects;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Project Management Workflow (Pilot) — the owner / administrator side.
 *
 *   draft → submitted → approved → open
 *              ↓  ↓
 *   changes_requested  rejected
 *
 * Covers the runtime layer end to end: creation, update, submission
 * completeness, every review decision, opening, authorization on each step
 * (including cross-organization access), the lifecycle transition guards, the
 * versioning rule and the audit trail.
 */
class ProjectManagementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
        Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
        Role::create(['name' => 'University Admin', 'slug' => 'university_admin', 'description' => '']);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function organization(string $name = 'Test Org'): Organization
    {
        return Organization::create(['name' => $name, 'type' => 'company']);
    }

    /**
     * A user holding a role, optionally administering an organization.
     */
    private function user(string $email, string $role, ?Organization $organization = null): User
    {
        $user = User::forceCreate([
            'name' => 'User '.$email,
            'email' => $email,
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->attach(Role::where('slug', $role)->first()->id);

        if ($organization !== null) {
            $user->organizations()->attach($organization->id, [
                'role_in_org' => 'admin',
                'status' => 'active',
            ]);
        }

        return $user->fresh();
    }

    private function learner(string $email = 'learner@test.com'): User
    {
        $user = $this->user($email, 'learner');

        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'visibility' => 'private',
            'consent_given' => true,
        ]);

        return $user->fresh();
    }

    private function skill(): Skill
    {
        return Skill::first() ?? Skill::create(['name' => 'PHP', 'slug' => 'php', 'category' => 'backend']);
    }

    /**
     * A complete, submittable creation payload.
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'company_sponsored',
            'title' => 'Pilot Project',
            'description' => 'Build the pilot deliverable.',
            'objectives' => 'Learn to ship software.',
            'domain' => 'Software',
            'difficulty' => 3,
            'work_mode' => 'remote',
            'capacity' => 2,
            'confidentiality' => 'public',
            'start_date' => now()->addDays(20)->toDateString(),
            'end_date' => now()->addDays(50)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
            'required_skills' => [
                ['skill_id' => $this->skill()->id, 'minimum_level' => 3, 'is_critical_entry' => true],
            ],
            'roles' => [
                ['title' => 'Backend Developer'],
            ],
        ], $overrides);
    }

    /**
     * A project persisted directly in a given lifecycle state. Bypasses the
     * workflow on purpose, so each test starts from the state it is about.
     */
    private function projectInState(string $status, ?User $owner = null, ?Organization $organization = null): Project
    {
        $owner ??= $this->user('owner@test.com', 'company_admin', $this->organization());

        return Project::create([
            'organization_id' => $organization?->id ?? $owner->organizations()->first()?->id,
            'owner_id' => $owner->id,
            'title' => 'Existing Project',
            'description' => 'Already described.',
            'objectives' => 'Already scoped.',
            'type' => 'company_sponsored',
            'status' => $status,
            'confidentiality' => 'public',
            'capacity' => 2,
            'work_mode' => 'remote',
            'difficulty' => 3,
            'start_date' => now()->addDays(20)->toDateString(),
            'end_date' => now()->addDays(50)->toDateString(),
            'application_deadline' => now()->addDays(10)->toDateString(),
            'version' => 1,
        ]);
    }

    /**
     * A project with the child rows submission requires.
     */
    private function completeProject(string $status = Project::STATUS_DRAFT, ?User $owner = null): Project
    {
        $project = $this->projectInState($status, $owner);

        $project->requiredSkills()->create([
            'skill_id' => $this->skill()->id,
            'minimum_level' => 3,
            'is_critical_entry' => true,
        ]);

        $project->projectRoles()->create(['title' => 'Backend Developer', 'is_active' => true]);

        return $project->fresh();
    }

    // -----------------------------------------------------------------
    // A. Creation
    // -----------------------------------------------------------------

    public function test_an_authorized_company_representative_can_create_a_draft_project(): void
    {
        $organization = $this->organization();
        $actor = $this->user('company@test.com', 'company_admin', $organization);

        Sanctum::actingAs($actor);

        $response = $this->postJson('/api/v1/projects', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', Project::STATUS_DRAFT)
            ->assertJsonPath('data.type', 'company_sponsored')
            ->assertJsonPath('data.version', 1);

        $project = Project::find($response->json('data.id'));

        // Owner and organization are derived, never taken from the payload.
        $this->assertSame($actor->id, $project->owner_id);
        $this->assertSame($organization->id, $project->organization_id);

        // The child collections were persisted.
        $this->assertSame(1, $project->requiredSkills()->count());
        $this->assertSame(1, $project->projectRoles()->count());

        $this->assertDatabaseHas('audit_events', [
            'action' => 'project.created',
            'entity_type' => 'project',
            'entity_id' => $project->id,
        ]);
    }

    public function test_a_learner_cannot_create_a_project(): void
    {
        Sanctum::actingAs($this->learner());

        $this->postJson('/api/v1/projects', $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_CREATE_FORBIDDEN');

        $this->assertSame(0, Project::count());
    }

    public function test_creation_ignores_a_supplied_status_and_organization(): void
    {
        $ownOrganization = $this->organization('Own Org');
        $otherOrganization = $this->organization('Other Org');
        $actor = $this->user('company@test.com', 'company_admin', $ownOrganization);

        Sanctum::actingAs($actor);

        $response = $this->postJson('/api/v1/projects', $this->payload([
            'status' => Project::STATUS_OPEN,
            'organization_id' => $otherOrganization->id,
        ]))->assertStatus(201);

        $project = Project::find($response->json('data.id'));

        // A project is never created open, and never into someone else's org.
        $this->assertSame(Project::STATUS_DRAFT, $project->status);
        $this->assertSame($ownOrganization->id, $project->organization_id);
    }

    public function test_creation_validates_the_project_type(): void
    {
        $actor = $this->user('company@test.com', 'company_admin', $this->organization());

        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/projects', $this->payload(['type' => 'volunteering']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['type']);
    }

    public function test_creation_rejects_an_end_date_before_the_start_date(): void
    {
        $actor = $this->user('company@test.com', 'company_admin', $this->organization());

        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/projects', $this->payload([
            'start_date' => now()->addDays(40)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_INVALID_DATES');

        $this->assertSame(0, Project::count());
    }

    // -----------------------------------------------------------------
    // B. Update
    // -----------------------------------------------------------------

    public function test_the_owner_can_update_a_draft_project(): void
    {
        $project = $this->completeProject();
        Sanctum::actingAs($project->owner);

        $this->patchJson("/api/v1/projects/{$project->id}", ['title' => 'Renamed Project'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed Project');

        $this->assertSame('Renamed Project', $project->fresh()->title);
    }

    public function test_another_user_cannot_update_someone_elses_project(): void
    {
        $project = $this->completeProject();
        $intruder = $this->user('intruder@test.com', 'company_admin', $this->organization());

        Sanctum::actingAs($intruder);

        $this->patchJson("/api/v1/projects/{$project->id}", ['title' => 'Hijacked'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_NOT_OWNED');

        $this->assertSame('Existing Project', $project->fresh()->title);
    }

    /**
     * A representative of a DIFFERENT organization must not be able to touch
     * the project even though they hold the same role.
     */
    public function test_a_project_from_another_organization_cannot_be_updated(): void
    {
        $project = $this->completeProject();

        $otherOrganization = $this->organization('Other Org');
        $foreignAdmin = $this->user('foreign@test.com', 'company_admin', $otherOrganization);

        Sanctum::actingAs($foreignAdmin);

        $this->patchJson("/api/v1/projects/{$project->id}", ['title' => 'Cross-org edit'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_NOT_OWNED');
    }

    /**
     * The type is immutable once the project is live: editing is only possible
     * in the revisable window, so an OPEN project cannot be edited at all and
     * its type can therefore never change while it is receiving applications.
     */
    public function test_the_project_type_cannot_change_once_the_project_is_open(): void
    {
        $project = $this->completeProject(Project::STATUS_OPEN);
        Sanctum::actingAs($project->owner);

        $this->patchJson("/api/v1/projects/{$project->id}", ['type' => 'simulation'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_NOT_EDITABLE');

        $this->assertSame('company_sponsored', $project->fresh()->type);
    }

    public function test_the_type_can_still_change_while_the_project_is_a_draft(): void
    {
        $project = $this->completeProject();
        Sanctum::actingAs($project->owner);

        $this->patchJson("/api/v1/projects/{$project->id}", ['type' => 'simulation'])
            ->assertOk()
            ->assertJsonPath('data.type', 'simulation');
    }

    public function test_the_version_is_bumped_only_when_a_matching_field_changes(): void
    {
        $project = $this->completeProject();
        Sanctum::actingAs($project->owner);

        // Descriptive only — the matching snapshot is unaffected.
        $this->patchJson("/api/v1/projects/{$project->id}", ['title' => 'New title'])
            ->assertOk()
            ->assertJsonPath('data.version', 1);

        // `difficulty` is part of the FastAPI project snapshot.
        $this->patchJson("/api/v1/projects/{$project->id}", ['difficulty' => 4])
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->assertSame(2, $project->fresh()->version);
    }

    // -----------------------------------------------------------------
    // C. Submission
    // -----------------------------------------------------------------

    public function test_a_complete_draft_can_be_submitted(): void
    {
        $project = $this->completeProject();
        Sanctum::actingAs($project->owner);

        $this->postJson("/api/v1/projects/{$project->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_SUBMITTED);

        $this->assertSame(Project::STATUS_SUBMITTED, $project->fresh()->status);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'project.submitted',
            'entity_id' => $project->id,
        ]);
    }

    public function test_an_incomplete_project_cannot_be_submitted_and_keeps_its_status(): void
    {
        $project = $this->projectInState(Project::STATUS_DRAFT);
        Sanctum::actingAs($project->owner);

        $response = $this->postJson("/api/v1/projects/{$project->id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_INCOMPLETE');

        // Every missing piece is reported at once, not just the first.
        $missing = $response->json('details.missing');
        $this->assertContains('required_skills', $missing);
        $this->assertContains('roles', $missing);

        $this->assertSame(Project::STATUS_DRAFT, $project->fresh()->status);
    }

    public function test_another_user_cannot_submit_someone_elses_project(): void
    {
        $project = $this->completeProject();
        $intruder = $this->user('intruder@test.com', 'company_admin', $this->organization());

        Sanctum::actingAs($intruder);

        $this->postJson("/api/v1/projects/{$project->id}/submit")
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_NOT_OWNED');

        $this->assertSame(Project::STATUS_DRAFT, $project->fresh()->status);
    }

    // -----------------------------------------------------------------
    // D. Approval
    // -----------------------------------------------------------------

    public function test_an_administrator_can_approve_a_submitted_project(): void
    {
        $project = $this->completeProject(Project::STATUS_SUBMITTED);
        $admin = $this->user('admin@test.com', 'admin');

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/projects/{$project->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_APPROVED);

        $fresh = $project->fresh();
        $this->assertSame(Project::STATUS_APPROVED, $fresh->status);
        $this->assertSame($admin->id, $fresh->approved_by);
        $this->assertNotNull($fresh->approved_at);

        // Approval must NOT activate or open the project.
        $this->assertNotSame(Project::STATUS_ACTIVE, $fresh->status);
        $this->assertNotSame(Project::STATUS_OPEN, $fresh->status);
    }

    public function test_the_project_owner_cannot_approve_their_own_project(): void
    {
        $project = $this->completeProject(Project::STATUS_SUBMITTED);

        Sanctum::actingAs($project->owner);

        $this->postJson("/api/v1/projects/{$project->id}/approve")
            ->assertStatus(403);

        $this->assertSame(Project::STATUS_SUBMITTED, $project->fresh()->status);
    }

    public function test_a_learner_cannot_approve_a_project(): void
    {
        $project = $this->completeProject(Project::STATUS_SUBMITTED);

        Sanctum::actingAs($this->learner());

        $this->postJson("/api/v1/projects/{$project->id}/approve")
            ->assertStatus(403);

        $this->assertSame(Project::STATUS_SUBMITTED, $project->fresh()->status);
    }

    public function test_a_draft_project_cannot_be_approved(): void
    {
        $project = $this->completeProject();
        Sanctum::actingAs($this->user('admin@test.com', 'admin'));

        $this->postJson("/api/v1/projects/{$project->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_INVALID_TRANSITION');

        $this->assertSame(Project::STATUS_DRAFT, $project->fresh()->status);
    }

    // -----------------------------------------------------------------
    // E. Request changes
    // -----------------------------------------------------------------

    public function test_an_administrator_can_request_changes_and_the_reason_is_recorded(): void
    {
        $project = $this->completeProject(Project::STATUS_SUBMITTED);
        $admin = $this->user('admin@test.com', 'admin');

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/projects/{$project->id}/request-changes", [
            'reason' => 'Please add the missing learning outcomes.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_CHANGES_REQUESTED);

        $audit = AuditEvent::where('action', 'project.changes_requested')
            ->where('entity_id', $project->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('Please add the missing learning outcomes.', $audit->after['reason']);
        $this->assertSame(Project::STATUS_SUBMITTED, $audit->before['status']);
    }

    public function test_requesting_changes_without_a_reason_is_refused(): void
    {
        $project = $this->completeProject(Project::STATUS_SUBMITTED);
        Sanctum::actingAs($this->user('admin@test.com', 'admin'));

        $this->postJson("/api/v1/projects/{$project->id}/request-changes")
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_REASON_REQUIRED');

        $this->assertSame(Project::STATUS_SUBMITTED, $project->fresh()->status);
    }

    public function test_the_owner_cannot_request_changes_on_their_own_project(): void
    {
        $project = $this->completeProject(Project::STATUS_SUBMITTED);

        Sanctum::actingAs($project->owner);

        $this->postJson("/api/v1/projects/{$project->id}/request-changes", ['reason' => 'Nope'])
            ->assertStatus(403);
    }

    public function test_a_project_with_changes_requested_can_be_edited_and_resubmitted(): void
    {
        $project = $this->completeProject(Project::STATUS_CHANGES_REQUESTED);
        Sanctum::actingAs($project->owner);

        $this->patchJson("/api/v1/projects/{$project->id}", ['objectives' => 'Now with clearer objectives.'])
            ->assertOk();

        $this->postJson("/api/v1/projects/{$project->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_SUBMITTED);
    }

    // -----------------------------------------------------------------
    // F. Rejection
    // -----------------------------------------------------------------

    public function test_an_administrator_can_reject_a_submitted_project(): void
    {
        $project = $this->completeProject(Project::STATUS_SUBMITTED);
        Sanctum::actingAs($this->user('admin@test.com', 'admin'));

        $this->postJson("/api/v1/projects/{$project->id}/reject", ['reason' => 'Out of scope.'])
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_REJECTED);

        $this->assertSame(Project::STATUS_REJECTED, $project->fresh()->status);
    }

    public function test_a_non_admin_cannot_reject_a_project(): void
    {
        $project = $this->completeProject(Project::STATUS_SUBMITTED);

        Sanctum::actingAs($project->owner);

        $this->postJson("/api/v1/projects/{$project->id}/reject")
            ->assertStatus(403);

        $this->assertSame(Project::STATUS_SUBMITTED, $project->fresh()->status);
    }

    // -----------------------------------------------------------------
    // G. Open
    // -----------------------------------------------------------------

    public function test_an_approved_project_can_be_opened(): void
    {
        $project = $this->completeProject(Project::STATUS_APPROVED);
        Sanctum::actingAs($project->owner);

        $this->postJson("/api/v1/projects/{$project->id}/open")
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_OPEN);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'project.opened',
            'entity_id' => $project->id,
        ]);
    }

    public function test_a_draft_project_cannot_be_opened(): void
    {
        $project = $this->completeProject();
        Sanctum::actingAs($project->owner);

        $this->postJson("/api/v1/projects/{$project->id}/open")
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_INVALID_TRANSITION');

        $this->assertSame(Project::STATUS_DRAFT, $project->fresh()->status);
    }

    public function test_a_submitted_project_cannot_be_opened(): void
    {
        $project = $this->completeProject(Project::STATUS_SUBMITTED);
        Sanctum::actingAs($project->owner);

        $this->postJson("/api/v1/projects/{$project->id}/open")
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_INVALID_TRANSITION');

        $this->assertSame(Project::STATUS_SUBMITTED, $project->fresh()->status);
    }

    public function test_a_rejected_project_cannot_be_opened(): void
    {
        $project = $this->completeProject(Project::STATUS_REJECTED);
        Sanctum::actingAs($project->owner);

        $this->postJson("/api/v1/projects/{$project->id}/open")
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_INVALID_TRANSITION');

        $this->assertSame(Project::STATUS_REJECTED, $project->fresh()->status);
    }

    public function test_an_outsider_cannot_open_someone_elses_project(): void
    {
        $project = $this->completeProject(Project::STATUS_APPROVED);
        $intruder = $this->user('intruder@test.com', 'company_admin', $this->organization());

        Sanctum::actingAs($intruder);

        $this->postJson("/api/v1/projects/{$project->id}/open")
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_NOT_OWNED');
    }

    // -----------------------------------------------------------------
    // H. Interaction with the existing application workflow
    // -----------------------------------------------------------------

    public function test_an_opened_project_is_discoverable_and_accepts_applications(): void
    {
        $project = $this->completeProject(Project::STATUS_APPROVED);
        Sanctum::actingAs($project->owner);
        $this->postJson("/api/v1/projects/{$project->id}/open")->assertOk();

        $learner = $this->learner();

        // The project requires the skill as a CRITICAL entry requirement, so the
        // learner needs it evaluated above the minimum to be eligible. Created
        // directly here because eligibility itself is already covered by
        // ProjectEligibilityServiceTest — this test is about the project's
        // status gating the application, not about the eligibility rules.
        SkillEvaluation::create([
            'student_profile_id' => $learner->studentProfile->id,
            'skill_id' => $this->skill()->id,
            'level' => 4,
            'confidence' => 1.0,
            'algorithm_version' => 'test-v1',
            'calculated_at' => now(),
        ]);

        // Discoverable in the catalog the learner already uses.
        Sanctum::actingAs($learner);
        $catalog = $this->getJson('/api/v1/projects')->assertOk();
        $this->assertContains($project->id, collect($catalog->json('data'))->pluck('id')->all());

        $this->postJson("/api/v1/projects/{$project->id}/applications", [])
            ->assertStatus(201);
    }

    /**
     * The whole point of the approval gate: a project that has been approved
     * but not opened is NOT yet accepting applications.
     */
    public function test_an_approved_but_unopened_project_rejects_applications(): void
    {
        $project = $this->completeProject(Project::STATUS_APPROVED);

        Sanctum::actingAs($this->learner());

        $this->postJson("/api/v1/projects/{$project->id}/applications", [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_NOT_AVAILABLE');
    }

    public function test_a_draft_project_is_not_discoverable(): void
    {
        $project = $this->completeProject();

        Sanctum::actingAs($this->learner());

        $catalog = $this->getJson('/api/v1/projects')->assertOk();
        $this->assertNotContains($project->id, collect($catalog->json('data'))->pluck('id')->all());
    }
}
