<?php

namespace Tests\Feature\Projects;

use App\Models\Application;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\ProjectTeam;
use App\Models\ProjectTeamMember;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Staffing a project and kicking it off.
 *
 * These two endpoints close the gap that made `active` unreachable: until they
 * existed, `open → selection` was declared in the model with nothing to call
 * it, and `selection` had no outgoing edge at all.
 *
 * The rules pinned here are the ones that protect the data rather than the
 * flow: only ACCEPTED applications can be staffed, nobody is staffed twice, the
 * team cannot exceed capacity, and a project with an empty team cannot start.
 */
class ProjectTeamAndStartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
        Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function organization(string $name = 'Test Org'): Organization
    {
        return Organization::forceCreate([
            'name' => $name,
            'type' => 'company',
            'verification_status' => 'verified',
        ]);
    }

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

    private function learner(string $email): User
    {
        $user = $this->user($email, 'learner');

        StudentProfile::forceCreate([
            'user_id' => $user->id,
            'visibility' => 'private',
            'consent_given' => true,
        ]);

        return $user->fresh();
    }

    private function project(User $owner, string $status = Project::STATUS_OPEN, int $capacity = 3): Project
    {
        return Project::create([
            'organization_id' => $owner->organizations()->first()?->id,
            'owner_id' => $owner->id,
            'title' => 'Source Project',
            'type' => 'company_sponsored',
            'status' => $status,
            'confidentiality' => 'public',
            'capacity' => $capacity,
            'version' => 1,
        ]);
    }

    private function application(Project $project, User $applicant, string $status = Application::STATUS_ACCEPTED): Application
    {
        return Application::create([
            'project_id' => $project->id,
            'applicant_id' => $applicant->id,
            'status' => $status,
            'project_version' => 1,
        ]);
    }

    // -----------------------------------------------------------------
    // Assignments
    // -----------------------------------------------------------------

    public function test_an_owner_can_staff_an_accepted_application(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $learner = $this->learner('learner@test.com');

        $project = $this->project($owner);
        $application = $this->application($project, $learner);

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/projects/{$project->id}/team/assignments", [
            'name' => 'Cohort A',
            'members' => [
                ['application_id' => $application->id, 'project_role' => 'Backend Developer'],
            ],
        ])->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Cohort A')
            ->assertJsonPath('data.members_count', 1);

        $this->assertSame($learner->id, $response->json('data.members.0.user_id'));
        $this->assertSame('Backend Developer', $response->json('data.members.0.project_role'));

        $this->assertSame(1, ProjectTeamMember::where('assignment_state', 'active')->count());
    }

    public function test_the_first_assignment_moves_an_open_project_into_selection(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $learner = $this->learner('learner@test.com');

        $project = $this->project($owner, Project::STATUS_OPEN);
        $application = $this->application($project, $learner);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/projects/{$project->id}/team/assignments", [
            'members' => [['application_id' => $application->id]],
        ])->assertStatus(201);

        // Staffing a project means applications are no longer being taken.
        $this->assertSame(Project::STATUS_SELECTION, $project->fresh()->status);
    }

    public function test_only_accepted_applications_can_be_staffed(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $learner = $this->learner('learner@test.com');

        $project = $this->project($owner);
        $application = $this->application($project, $learner, Application::STATUS_SHORTLISTED);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/projects/{$project->id}/team/assignments", [
            'members' => [['application_id' => $application->id]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'APPLICATION_NOT_ACCEPTED');

        $this->assertSame(0, ProjectTeam::count());
    }

    public function test_an_application_from_another_project_is_refused(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $learner = $this->learner('learner@test.com');

        $project = $this->project($owner);
        $other = $this->project($owner);

        $foreign = $this->application($other, $learner);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/projects/{$project->id}/team/assignments", [
            'members' => [['application_id' => $foreign->id]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'APPLICATION_PROJECT_MISMATCH');
    }

    public function test_the_same_applicant_cannot_be_staffed_twice(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $learner = $this->learner('learner@test.com');

        $project = $this->project($owner);
        $application = $this->application($project, $learner);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/projects/{$project->id}/team/assignments", [
            'members' => [['application_id' => $application->id]],
        ])->assertStatus(201);

        $this->postJson("/api/v1/projects/{$project->id}/team/assignments", [
            'members' => [['application_id' => $application->id]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TEAM_MEMBER_ALREADY_ASSIGNED');
    }

    public function test_the_team_cannot_exceed_the_project_capacity(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());

        $project = $this->project($owner, Project::STATUS_OPEN, 1);

        $first = $this->application($project, $this->learner('one@test.com'));
        $second = $this->application($project, $this->learner('two@test.com'));

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/projects/{$project->id}/team/assignments", [
            'members' => [
                ['application_id' => $first->id],
                ['application_id' => $second->id],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_FULL');

        // The transaction rolled back: a partially staffed team would be worse
        // than a refusal.
        $this->assertSame(0, ProjectTeamMember::count());
    }

    public function test_a_project_that_is_not_open_or_in_selection_cannot_be_staffed(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $learner = $this->learner('learner@test.com');

        $project = $this->project($owner, Project::STATUS_DRAFT);
        $application = $this->application($project, $learner);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/projects/{$project->id}/team/assignments", [
            'members' => [['application_id' => $application->id]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_NOT_ASSIGNABLE');
    }

    public function test_a_non_owner_cannot_staff_a_project(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization('Acme'));
        $stranger = $this->user('stranger@test.com', 'company_admin', $this->organization('Globex'));
        $learner = $this->learner('learner@test.com');

        $project = $this->project($owner);
        $application = $this->application($project, $learner);

        Sanctum::actingAs($stranger);

        $this->postJson("/api/v1/projects/{$project->id}/team/assignments", [
            'members' => [['application_id' => $application->id]],
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_NOT_OWNED');
    }

    public function test_the_same_application_twice_in_one_payload_is_a_validation_error(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $learner = $this->learner('learner@test.com');

        $project = $this->project($owner);
        $application = $this->application($project, $learner);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/projects/{$project->id}/team/assignments", [
            'members' => [
                ['application_id' => $application->id],
                ['application_id' => $application->id],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_an_empty_member_list_is_a_validation_error(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/projects/{$project->id}/team/assignments", ['members' => []])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    // -----------------------------------------------------------------
    // Start
    // -----------------------------------------------------------------

    public function test_a_project_with_an_empty_team_cannot_start(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_SELECTION);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/projects/{$project->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_TEAM_EMPTY');

        $this->assertSame(Project::STATUS_SELECTION, $project->fresh()->status);
    }

    public function test_a_staffed_project_can_start(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $learner = $this->learner('learner@test.com');

        $project = $this->project($owner, Project::STATUS_SELECTION);
        $application = $this->application($project, $learner);

        $team = ProjectTeam::create(['project_id' => $project->id, 'name' => 'Cohort A']);

        ProjectTeamMember::create([
            'project_team_id' => $team->id,
            'user_id' => $learner->id,
            'assignment_state' => 'active',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/projects/{$project->id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_ACTIVE);

        $this->assertSame(Project::STATUS_ACTIVE, $project->fresh()->status);
    }

    public function test_a_project_cannot_start_straight_from_open(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $learner = $this->learner('learner@test.com');

        $project = $this->project($owner, Project::STATUS_OPEN);
        $application = $this->application($project, $learner);

        $team = ProjectTeam::create(['project_id' => $project->id, 'name' => 'Cohort A']);

        ProjectTeamMember::create([
            'project_team_id' => $team->id,
            'user_id' => $learner->id,
            'assignment_state' => 'active',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($owner);

        // `open` must go through `selection` first — the assignment endpoint is
        // what performs that move, so skipping it is a genuine state error.
        $this->postJson("/api/v1/projects/{$project->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_INVALID_TRANSITION');
    }

    public function test_a_non_owner_cannot_start_a_project(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization('Acme'));
        $stranger = $this->user('stranger@test.com', 'company_admin', $this->organization('Globex'));

        $project = $this->project($owner, Project::STATUS_SELECTION);

        Sanctum::actingAs($stranger);

        $this->postJson("/api/v1/projects/{$project->id}/start")
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_NOT_OWNED');
    }

    public function test_the_dispatcher_can_begin_selection(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_OPEN);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/projects/{$project->id}/transitions", ['to' => 'selection'])
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_SELECTION);
    }

    // -----------------------------------------------------------------
    // Identifying the role instead of naming it
    // -----------------------------------------------------------------

    public function test_a_role_can_be_identified_by_id(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $learner = $this->learner('learner@test.com');

        $project = $this->project($owner);
        $application = $this->application($project, $learner);

        $role = ProjectRole::create([
            'project_id' => $project->id,
            'title' => 'Backend Developer',
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        // A screen renders the project's roles, so it holds the id, not the
        // title. The server resolves it — and stores the title, which is what
        // the team payload and the export show.
        $this->postJson("/api/v1/projects/{$project->id}/team/assignments", [
            'members' => [
                ['application_id' => $application->id, 'project_role_id' => $role->id],
            ],
        ])->assertStatus(201)
            ->assertJsonPath('data.members.0.project_role', 'Backend Developer');
    }

    public function test_a_role_from_another_project_is_refused(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $learner = $this->learner('learner@test.com');

        $project = $this->project($owner);
        $other = $this->project($owner, Project::STATUS_DRAFT);
        $application = $this->application($project, $learner);

        $foreignRole = ProjectRole::create([
            'project_id' => $other->id,
            'title' => 'Data Analyst',
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        // Resolved against THIS project's roles, so an id from another project
        // cannot be smuggled in and stored as this team's role.
        $this->postJson("/api/v1/projects/{$project->id}/team/assignments", [
            'members' => [
                ['application_id' => $application->id, 'project_role_id' => $foreignRole->id],
            ],
        ])->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_ROLE_NOT_FOUND');

        $this->assertSame(0, ProjectTeamMember::count());
    }
}
