<?php

namespace Tests\Feature\Projects;

use App\Models\Application;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The company's own closing moves: reopen, revise, complete, cancel, archive.
 *
 * These were the four buttons the project screen offered with nothing behind
 * them. Each one is tested from the states it is meant for, and — more
 * importantly — from the states it is NOT meant for, because "a company can
 * retire its own live project" must not turn into "a company can rewrite a
 * finished one".
 */
class ProjectClosingTransitionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
        Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
        Role::create(['name' => 'Company Admin', 'slug' => 'company_admin', 'description' => '']);
    }

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

    private function project(User $owner, string $status, array $extra = []): Project
    {
        return Project::create(array_merge([
            'organization_id' => $owner->organizations()->first()?->id,
            'owner_id' => $owner->id,
            'title' => 'Data Platform',
            'type' => 'company_sponsored',
            'status' => $status,
            'confidentiality' => 'public',
            'capacity' => 3,
            'version' => 1,
        ], $extra));
    }

    private function move(Project $project, string $to, array $body = []): TestResponse
    {
        return $this->postJson("/api/v1/projects/{$project->id}/transitions", array_merge(['to' => $to], $body));
    }

    // -----------------------------------------------------------------
    // reopen: selection → open
    // -----------------------------------------------------------------

    public function test_a_project_can_go_back_to_open_and_keep_its_team(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_SELECTION);

        Sanctum::actingAs($owner);

        $this->move($project, Project::STATUS_OPEN)
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_OPEN);

        $this->assertSame(Project::STATUS_OPEN, $project->fresh()->status);
    }

    // -----------------------------------------------------------------
    // revise: rejected → draft
    // -----------------------------------------------------------------

    public function test_a_rejected_project_can_be_reworked_into_a_new_draft_version(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_REJECTED, ['version' => 3]);

        Sanctum::actingAs($owner);

        $this->move($project, Project::STATUS_DRAFT)
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_DRAFT)
            // A new version: the reviewer's decision applied to the previous one.
            ->assertJsonPath('data.version', 4);
    }

    // -----------------------------------------------------------------
    // complete: active → completed
    // -----------------------------------------------------------------

    public function test_a_running_project_can_be_completed(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_ACTIVE);

        Sanctum::actingAs($owner);

        $this->move($project, Project::STATUS_COMPLETED)
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_COMPLETED);
    }

    public function test_a_draft_cannot_be_completed(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_DRAFT);

        Sanctum::actingAs($owner);

        $this->move($project, Project::STATUS_COMPLETED)
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_INVALID_TRANSITION');
    }

    // -----------------------------------------------------------------
    // cancel: a live project the company owns
    // -----------------------------------------------------------------

    public function test_the_company_can_cancel_its_own_open_project(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_OPEN);

        Sanctum::actingAs($owner);

        $this->move($project, Project::STATUS_CANCELLED, ['reason' => 'Budget pulled.'])
            ->assertOk()
            ->assertJsonPath('data.status', Project::STATUS_CANCELLED);
    }

    public function test_cancelling_withdraws_the_open_applications(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_OPEN);

        $application = Application::create([
            'project_id' => $project->id,
            'applicant_id' => $this->learner()->id,
            'status' => Application::STATUS_SUBMITTED,
            'project_version' => 1,
        ]);

        Sanctum::actingAs($owner);

        $this->move($project, Project::STATUS_CANCELLED)->assertOk();

        // A pending application to a project that no longer exists is noise in
        // the applicant's list.
        $this->assertSame(Application::STATUS_WITHDRAWN, $application->fresh()->status);
    }

    public function test_a_draft_cannot_be_cancelled_by_its_owner(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_DRAFT);

        Sanctum::actingAs($owner);

        // The admin route may retire a draft; the owner's own cancel is for a
        // project that is already in front of people.
        $this->move($project, Project::STATUS_CANCELLED)
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_INVALID_TRANSITION');
    }

    public function test_somebody_else_cannot_cancel_the_project(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization('Acme'));
        $other = $this->user('other@test.com', 'company_admin', $this->organization('Globex'));
        $project = $this->project($owner, Project::STATUS_OPEN);

        Sanctum::actingAs($other);

        // `PROJECT_NOT_OWNED`, not `PROJECT_FORBIDDEN`: the codebase already
        // answers with this one everywhere else a project is managed by
        // somebody who does not own it, and the OpenAPI document promises it.
        $this->move($project, Project::STATUS_CANCELLED)
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_NOT_OWNED');

        $this->assertSame(Project::STATUS_OPEN, $project->fresh()->status);
    }

    // -----------------------------------------------------------------
    // archive: the three finished states
    // -----------------------------------------------------------------

    public function test_completed_cancelled_and_rejected_projects_can_be_archived(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());

        Sanctum::actingAs($owner);

        foreach ([Project::STATUS_COMPLETED, Project::STATUS_CANCELLED, Project::STATUS_REJECTED] as $status) {
            $project = $this->project($owner, $status);

            $this->move($project, Project::STATUS_ARCHIVED)
                ->assertOk()
                ->assertJsonPath('data.status', Project::STATUS_ARCHIVED);
        }
    }

    public function test_a_live_project_cannot_be_archived(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_OPEN);

        Sanctum::actingAs($owner);

        // Archiving is not a way to skip the workflow: close it first.
        $this->move($project, Project::STATUS_ARCHIVED)
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_INVALID_TRANSITION');
    }

    public function test_an_archived_project_is_final(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_ARCHIVED);

        Sanctum::actingAs($owner);

        $response = $this->move($project, Project::STATUS_COMPLETED)
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_INVALID_TRANSITION');

        $this->assertSame([], $response->json('details.allowed_transitions'));
    }

    // -----------------------------------------------------------------
    // The set a client is told about
    // -----------------------------------------------------------------

    public function test_the_allowed_transitions_name_every_new_move(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_SELECTION);

        Sanctum::actingAs($owner);

        // An invalid target returns the executable set for the CURRENT state.
        $response = $this->move($project, Project::STATUS_DRAFT)
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_INVALID_TRANSITION');

        $allowed = $response->json('details.allowed_transitions');
        sort($allowed);

        $this->assertSame([Project::STATUS_ACTIVE, Project::STATUS_CANCELLED, Project::STATUS_OPEN], $allowed);
    }
}
