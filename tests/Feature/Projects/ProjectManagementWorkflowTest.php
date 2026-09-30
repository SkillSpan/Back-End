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

    /**
     * A VERIFIED company — the only kind that may sponsor a company_sponsored
     * project. `forceCreate` is required because `verification_status` is
     * deliberately not mass-assignable on the Organization model.
     */
    private function organization(string $name = 'Test Org'): Organization
    {
        return Organization::forceCreate([
            'name' => $name,
            'type' => 'company',
            'verification_status' => 'verified',
        ]);
    }

    /**
     * An organization of another type, to prove a company_sponsored project
     * cannot be sponsored by a university or a training partner.
     */
    private function university(string $name = 'Test University'): Organization
    {
        return Organization::forceCreate([
            'name' => $name,
            'type' => 'university',
            'verification_status' => 'verified',
        ]);
    }

    /**
     * A company that has not been approved yet.
     */
    private function pendingCompany(string $name = 'Pending Company'): Organization
    {
        return Organization::forceCreate([
            'name' => $name,
            'type' => 'company',
            'verification_status' => 'pending',
        ]);
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

    // -----------------------------------------------------------------
    // I. Ownership — company_sponsored = COMPANY OWNED
    // -----------------------------------------------------------------

    public function test_a_company_sponsored_project_must_name_an_organization(): void
    {
        $admin = $this->user('admin@test.com', 'admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/projects', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_ORGANIZATION_REQUIRED');

        $this->assertSame(0, Project::count());
    }

    public function test_a_company_sponsored_project_cannot_be_sponsored_by_a_non_company(): void
    {
        $university = $this->university();
        $actor = $this->user('university@test.com', 'university_admin', $university);

        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/projects', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_ORGANIZATION_NOT_A_COMPANY');

        $this->assertSame(0, Project::count());
    }

    public function test_a_company_sponsored_project_cannot_be_sponsored_by_an_unapproved_company(): void
    {
        $company = $this->pendingCompany();
        $actor = $this->user('company@test.com', 'company_admin', $company);

        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/projects', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_ORGANIZATION_NOT_VERIFIED')
            ->assertJsonPath('details.verification_status', 'pending');
    }

    // -----------------------------------------------------------------
    // J. Ownership — administrator creating on behalf of a company
    // -----------------------------------------------------------------

    public function test_an_administrator_creating_on_behalf_of_a_company_does_not_become_the_owner(): void
    {
        $company = $this->organization('Sponsored Company');
        $representative = $this->user('rep@company.com', 'company_admin', $company);
        $admin = $this->user('admin@test.com', 'admin');

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/projects', $this->payload([
            'organization_id' => $company->id,
            'owner_id' => $representative->id,
        ]))->assertStatus(201);

        $project = Project::find($response->json('data.id'));

        $this->assertSame($representative->id, $project->owner_id);
        $this->assertSame($company->id, $project->organization_id);
        $this->assertNotSame($admin->id, $project->owner_id);
    }

    public function test_an_administrator_creating_on_behalf_of_a_company_must_name_the_representative(): void
    {
        $company = $this->organization('Sponsored Company');
        $this->user('rep@company.com', 'company_admin', $company);
        $admin = $this->user('admin@test.com', 'admin');

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/projects', $this->payload([
            'organization_id' => $company->id,
        ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_OWNER_REQUIRED');

        $this->assertSame(0, Project::count());
    }

    public function test_an_administrator_cannot_name_themselves_as_the_owner_of_a_company_project(): void
    {
        $company = $this->organization('Sponsored Company');
        $admin = $this->user('admin@test.com', 'admin');

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/projects', $this->payload([
            'organization_id' => $company->id,
            'owner_id' => $admin->id,
        ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_OWNER_MUST_BE_COMPANY_REPRESENTATIVE');

        $this->assertSame(0, Project::count());
    }

    public function test_an_administrator_cannot_assign_an_unrelated_user_as_the_owner(): void
    {
        $company = $this->organization('Sponsored Company');
        $outsider = $this->user('outsider@test.com', 'company_admin', $this->organization('Other Company'));
        $admin = $this->user('admin@test.com', 'admin');

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/projects', $this->payload([
            'organization_id' => $company->id,
            'owner_id' => $outsider->id,
        ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_OWNER_NOT_ORGANIZATION_REPRESENTATIVE');

        $this->assertSame(0, Project::count());
    }

    /**
     * A plain member of the company is not a company representative — the
     * pivot has to say `admin`.
     */
    public function test_an_administrator_cannot_assign_a_plain_member_as_the_owner(): void
    {
        $company = $this->organization('Sponsored Company');
        $member = $this->user('member@company.com', 'learner');
        $company->members()->attach($member->id, ['role_in_org' => 'member', 'status' => 'active']);

        Sanctum::actingAs($this->user('admin@test.com', 'admin'));

        $this->postJson('/api/v1/projects', $this->payload([
            'organization_id' => $company->id,
            'owner_id' => $member->id,
        ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_OWNER_NOT_ORGANIZATION_REPRESENTATIVE');
    }

    // -----------------------------------------------------------------
    // K. Ownership — simulation
    // -----------------------------------------------------------------

    public function test_an_authorized_actor_can_create_a_simulation_project(): void
    {
        $admin = $this->user('admin@test.com', 'admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/projects', $this->payload(['type' => 'simulation']))
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'simulation')
            ->assertJsonPath('data.organization_id', null);

        $project = Project::find($response->json('data.id'));

        $this->assertNull($project->organization_id);
        $this->assertSame($admin->id, $project->owner_id);
    }

    /**
     * The creator's organization must NOT be attached automatically — that is
     * what would silently turn a SkillSpan-internal simulation into a company
     * project.
     */
    public function test_a_simulation_is_not_automatically_linked_to_the_creators_organization(): void
    {
        $company = $this->organization('Sponsoring Company');
        $actor = $this->user('company@test.com', 'company_admin', $company);

        Sanctum::actingAs($actor);

        $response = $this->postJson('/api/v1/projects', $this->payload(['type' => 'simulation']))
            ->assertStatus(201);

        $this->assertNull(Project::find($response->json('data.id'))->organization_id);
    }

    public function test_a_simulation_can_name_an_organization_the_actor_administers(): void
    {
        $company = $this->organization('Sponsoring Company');
        $actor = $this->user('company@test.com', 'company_admin', $company);

        Sanctum::actingAs($actor);

        $response = $this->postJson('/api/v1/projects', $this->payload([
            'type' => 'simulation',
            'organization_id' => $company->id,
        ]))->assertStatus(201);

        $this->assertSame($company->id, Project::find($response->json('data.id'))->organization_id);
    }

    public function test_a_simulation_cannot_name_an_organization_the_actor_does_not_administer(): void
    {
        $own = $this->organization('Own Company');
        $other = $this->organization('Other Company');
        $actor = $this->user('company@test.com', 'company_admin', $own);

        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/projects', $this->payload([
            'type' => 'simulation',
            'organization_id' => $other->id,
        ]))
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_ORGANIZATION_NOT_ADMINISTERED');
    }

    public function test_a_simulation_follows_the_same_lifecycle(): void
    {
        $project = $this->completeProject(Project::STATUS_DRAFT);
        $project->forceFill(['type' => 'simulation', 'organization_id' => null])->save();
        $project = $project->fresh();

        Sanctum::actingAs($project->owner);

        $this->postJson("/api/v1/projects/{$project->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_SUBMITTED);

        Sanctum::actingAs($this->user('admin@test.com', 'admin'));

        $this->postJson("/api/v1/projects/{$project->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_APPROVED);

        Sanctum::actingAs($project->owner);

        $this->postJson("/api/v1/projects/{$project->id}/open")
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_OPEN);
    }

    /**
     * The conversion guard: a simulation must not become a "company project"
     * that names no company. A company representative is covered by the test
     * below (their company is derived from their own membership); this is the
     * case where there is genuinely no company to derive.
     */
    public function test_a_simulation_cannot_be_converted_to_company_sponsored_without_a_company(): void
    {
        $admin = $this->user('admin@test.com', 'admin');

        $simulation = $this->completeProject(Project::STATUS_DRAFT, $admin);
        $simulation->forceFill(['type' => 'simulation', 'organization_id' => null])->save();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/projects/{$simulation->id}", ['type' => 'company_sponsored'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_ORGANIZATION_REQUIRED');

        $this->assertSame('simulation', $simulation->fresh()->type);
    }

    public function test_an_administrator_can_convert_a_simulation_by_naming_the_company_and_its_representative(): void
    {
        $company = $this->organization('Sponsoring Company');
        $representative = $this->user('rep@company.com', 'company_admin', $company);
        $admin = $this->user('admin@test.com', 'admin');

        $simulation = $this->completeProject(Project::STATUS_DRAFT, $admin);
        $simulation->forceFill(['type' => 'simulation', 'organization_id' => null])->save();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/projects/{$simulation->id}", [
            'type' => 'company_sponsored',
            'organization_id' => $company->id,
            'owner_id' => $representative->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.type', 'company_sponsored');

        $fresh = $simulation->fresh();
        $this->assertSame($company->id, $fresh->organization_id);
        $this->assertSame($representative->id, $fresh->owner_id);
        $this->assertNotSame($admin->id, $fresh->owner_id);
    }

    public function test_a_company_representative_can_convert_their_simulation_into_a_company_project(): void
    {
        $company = $this->organization('Sponsoring Company');
        $actor = $this->user('company@test.com', 'company_admin', $company);

        $simulation = $this->completeProject(Project::STATUS_DRAFT, $actor);
        $simulation->forceFill(['type' => 'simulation', 'organization_id' => null])->save();

        Sanctum::actingAs($actor);

        $this->patchJson("/api/v1/projects/{$simulation->id}", ['type' => 'company_sponsored'])
            ->assertOk()
            ->assertJsonPath('data.type', 'company_sponsored');

        $fresh = $simulation->fresh();
        $this->assertSame($company->id, $fresh->organization_id);
        $this->assertSame($actor->id, $fresh->owner_id);
    }

    // -----------------------------------------------------------------
    // L. Ownership — security
    // -----------------------------------------------------------------

    /**
     * A cosmetic update must not be a way to re-point ownership.
     */
    public function test_a_representative_cannot_reassign_ownership_through_an_ordinary_update(): void
    {
        $company = $this->organization('Sponsoring Company');
        $actor = $this->user('company@test.com', 'company_admin', $company);
        $other = $this->user('other@company.com', 'company_admin', $company);

        $project = $this->completeProject(Project::STATUS_DRAFT, $actor);

        Sanctum::actingAs($actor);

        $this->patchJson("/api/v1/projects/{$project->id}", [
            'title' => 'Renamed',
            'owner_id' => $other->id,
            'organization_id' => $this->organization('Other Company')->id,
        ])->assertOk();

        $fresh = $project->fresh();
        $this->assertSame($actor->id, $fresh->owner_id);
        $this->assertSame($company->id, $fresh->organization_id);
    }

    public function test_an_administrator_cannot_review_a_project_they_own(): void
    {
        $admin = $this->user('admin@test.com', 'admin');

        // An internal simulation owned by the administrator themselves.
        $project = $this->completeProject(Project::STATUS_SUBMITTED, $admin);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/projects/{$project->id}/approve")
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_REVIEW_SELF_FORBIDDEN');

        $this->assertSame(Project::STATUS_SUBMITTED, $project->fresh()->status);
    }
}
