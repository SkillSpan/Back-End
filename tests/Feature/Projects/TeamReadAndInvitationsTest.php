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
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamReadAndInvitationsTest extends TestCase
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
            'title' => 'Data Platform',
            'type' => 'company_sponsored',
            'status' => $status,
            'confidentiality' => 'public',
            'capacity' => $capacity,
            'version' => 1,
        ]);
    }

    private function application(
        Project $project,
        User $applicant,
        string $status = Application::STATUS_ACCEPTED,
        ?ProjectRole $role = null,
    ): Application {
        return Application::create([
            'project_id' => $project->id,
            'applicant_id' => $applicant->id,
            'project_role_id' => $role?->id,
            'status' => $status,
            'project_version' => 1,
        ]);
    }

    private function staff(Project $project, Application $application, string $role = 'Data Analyst'): ProjectTeamMember
    {
        $team = ProjectTeam::firstOrCreate(['project_id' => $project->id], ['name' => null]);

        return ProjectTeamMember::create([
            'project_team_id' => $team->id,
            'user_id' => $application->applicant_id,
            'application_id' => $application->id,
            'project_role' => $role,
            'assignment_state' => 'active',
            'joined_at' => now(),
        ]);
    }

    public function test_the_owner_reads_the_team_with_unplaced_accepted_applicants(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner);

        $role = ProjectRole::create(['project_id' => $project->id, 'title' => 'Data Analyst']);

        $placed = $this->application($project, $this->learner('placed@test.com'), Application::STATUS_ACCEPTED, $role);
        $waiting = $this->application($project, $this->learner('waiting@test.com'), Application::STATUS_ACCEPTED, $role);
        $onHold = $this->application($project, $this->learner('hold@test.com'), Application::STATUS_WAITLISTED, $role);
        $this->application($project, $this->learner('rejected@test.com'), Application::STATUS_REJECTED, $role);

        $this->staff($project, $placed);

        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/v1/projects/{$project->id}/team")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.project.id', $project->id);

        $ids = collect($response->json('data.participants'))->pluck('application_id')->all();
        sort($ids);
        $expected = [$placed->id, $waiting->id];
        sort($expected);
        $this->assertSame($expected, $ids);

        $roleRow = collect($response->json('data.roles'))->firstWhere('name', 'Data Analyst');
        $this->assertSame(2, $roleRow['capacity']);
        $this->assertSame(1, $roleRow['assigned']);
        $this->assertSame(1, $roleRow['remaining']);
        $this->assertFalse($response->json('data.composition.valid'));
        $this->assertSame('Data Analyst', $response->json('data.composition.unfilled_roles.0.name'));

        $this->assertSame($onHold->id, $response->json('data.waitlist.0.application_id'));
    }

    public function test_a_staffed_participant_may_read_the_team(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner);

        $member = $this->learner('member@test.com');
        $this->staff($project, $this->application($project, $member));

        Sanctum::actingAs($member);

        $this->getJson("/api/v1/projects/{$project->id}/team")
            ->assertOk()
            ->assertJsonPath('data.project.id', $project->id);
    }

    public function test_an_unrelated_company_is_refused_the_team(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization('Acme'));
        $other = $this->user('other@test.com', 'company_admin', $this->organization('Globex'));
        $project = $this->project($owner);

        Sanctum::actingAs($other);

        $this->getJson("/api/v1/projects/{$project->id}/team")
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_TEAM_FORBIDDEN');
    }

    public function test_the_team_of_an_unknown_project_is_404(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/projects/999999/team')
            ->assertStatus(404)
            ->assertJsonPath('code', 'PROJECT_NOT_FOUND');
    }

    public function test_an_anonymous_caller_is_refused_the_team(): void
    {
        $this->getJson('/api/v1/projects/1/team')->assertStatus(401);
    }

    public function test_removing_a_participant_frees_the_seat_and_keeps_the_row(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_SELECTION);

        $application = $this->application($project, $this->learner('member@test.com'));
        $member = $this->staff($project, $application);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/projects/{$project->id}/team/assignments/{$application->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('project_team_members', [
            'id' => $member->id,
            'assignment_state' => 'removed',
        ]);

        $response = $this->getJson("/api/v1/projects/{$project->id}/team")->assertOk();
        $participant = collect($response->json('data.participants'))
            ->firstWhere('application_id', $application->id);

        $this->assertFalse($participant['assigned']);
        $this->assertNull($participant['assignment']);
    }

    public function test_removing_a_member_cancels_their_pending_invitation(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_SELECTION);

        $application = $this->application($project, $this->learner('member@test.com'));
        $member = $this->staff($project, $application);

        $invitation = TeamInvitation::create([
            'project_team_member_id' => $member->id,
            'project_id' => $project->id,
            'user_id' => $member->user_id,
            'project_role' => $member->project_role,
            'status' => TeamInvitation::STATUS_PENDING,
            'sent_at' => now(),
        ]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/projects/{$project->id}/team/assignments/{$application->id}")
            ->assertOk();

        $this->assertSame(TeamInvitation::STATUS_CANCELLED, $invitation->fresh()->status);
    }

    public function test_removing_someone_who_is_not_on_the_team_is_404(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_SELECTION);

        $this->staff($project, $this->application($project, $this->learner('other@test.com')));

        $application = $this->application($project, $this->learner('member@test.com'));

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/projects/{$project->id}/team/assignments/{$application->id}")
            ->assertStatus(404)
            ->assertJsonPath('code', 'TEAM_MEMBER_NOT_FOUND');
    }

    public function test_a_finished_project_team_is_locked(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_SELECTION);

        $application = $this->application($project, $this->learner('member@test.com'));
        $this->staff($project, $application);

        $project->update(['status' => Project::STATUS_ARCHIVED]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/projects/{$project->id}/team/assignments/{$application->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_TEAM_LOCKED');
    }

    public function test_another_company_cannot_remove_a_participant(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization('Acme'));
        $other = $this->user('other@test.com', 'company_admin', $this->organization('Globex'));
        $project = $this->project($owner, Project::STATUS_SELECTION);

        $application = $this->application($project, $this->learner('member@test.com'));
        $this->staff($project, $application);

        Sanctum::actingAs($other);

        $this->deleteJson("/api/v1/projects/{$project->id}/team/assignments/{$application->id}")
            ->assertStatus(403);
    }

    public function test_inviting_the_team_creates_one_pending_invitation_each(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_SELECTION);

        $first = $this->application($project, $this->learner('a@test.com'));
        $second = $this->application($project, $this->learner('b@test.com'));
        $this->staff($project, $first, 'Data Analyst');
        $this->staff($project, $second, 'Designer');

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/projects/{$project->id}/team/invitations", [
            'message' => 'Welcome aboard.',
        ])
            ->assertOk()
            ->assertJsonPath('data.sent', 2);

        $this->assertCount(2, $response->json('data.invitations'));

        $this->assertDatabaseHas('team_invitations', [
            'project_id' => $project->id,
            'project_role' => 'Data Analyst',
            'status' => TeamInvitation::STATUS_PENDING,
            'message' => 'Welcome aboard.',
        ]);
    }

    public function test_inviting_twice_sends_nothing_the_second_time(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_SELECTION);

        $this->staff($project, $this->application($project, $this->learner('a@test.com')));

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/projects/{$project->id}/team/invitations")
            ->assertOk()
            ->assertJsonPath('data.sent', 1);

        $this->postJson("/api/v1/projects/{$project->id}/team/invitations")
            ->assertOk()
            ->assertJsonPath('data.sent', 0);

        $this->assertSame(1, TeamInvitation::query()->where('project_id', $project->id)->count());
    }

    public function test_an_empty_team_cannot_be_invited(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_SELECTION);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/projects/{$project->id}/team/invitations")
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_TEAM_EMPTY');
    }

    public function test_the_invited_person_reads_and_accepts_their_invitation(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_SELECTION);

        $student = $this->learner('invitee@test.com');
        $this->staff($project, $this->application($project, $student));

        Sanctum::actingAs($owner);
        $invitationId = $this->postJson("/api/v1/projects/{$project->id}/team/invitations")
            ->assertOk()
            ->json('data.invitations.0.id');

        Sanctum::actingAs($student);

        $this->getJson("/api/v1/team-invitations/{$invitationId}")
            ->assertOk()
            ->assertJsonPath('data.status', TeamInvitation::STATUS_PENDING)
            ->assertJsonPath('data.project.id', $project->id);

        $this->postJson("/api/v1/team-invitations/{$invitationId}/response", ['response' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('data.status', TeamInvitation::STATUS_ACCEPTED);

        $this->assertNotNull(TeamInvitation::find($invitationId)->responded_at);
    }

    public function test_a_decline_is_recorded_and_shown_to_the_company(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_SELECTION);

        $student = $this->learner('invitee@test.com');
        $this->staff($project, $this->application($project, $student));

        Sanctum::actingAs($owner);
        $invitationId = $this->postJson("/api/v1/projects/{$project->id}/team/invitations")
            ->json('data.invitations.0.id');

        Sanctum::actingAs($student);
        $this->postJson("/api/v1/team-invitations/{$invitationId}/response", ['response' => 'declined'])
            ->assertOk()
            ->assertJsonPath('data.status', TeamInvitation::STATUS_DECLINED);

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/projects/{$project->id}/team")
            ->assertOk()
            ->assertJsonPath('data.participants.0.declined_invitation', true);
    }

    public function test_an_invitation_cannot_be_answered_twice(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_SELECTION);

        $student = $this->learner('invitee@test.com');
        $this->staff($project, $this->application($project, $student));

        Sanctum::actingAs($owner);
        $invitationId = $this->postJson("/api/v1/projects/{$project->id}/team/invitations")
            ->json('data.invitations.0.id');

        Sanctum::actingAs($student);
        $this->postJson("/api/v1/team-invitations/{$invitationId}/response", ['response' => 'accepted'])
            ->assertOk();

        $this->postJson("/api/v1/team-invitations/{$invitationId}/response", ['response' => 'declined'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVITATION_ALREADY_ANSWERED');
    }

    public function test_somebody_else_cannot_answer_the_invitation(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_SELECTION);

        $student = $this->learner('invitee@test.com');
        $this->staff($project, $this->application($project, $student));

        Sanctum::actingAs($owner);
        $invitationId = $this->postJson("/api/v1/projects/{$project->id}/team/invitations")
            ->json('data.invitations.0.id');

        Sanctum::actingAs($this->learner('stranger@test.com'));

        $this->getJson("/api/v1/team-invitations/{$invitationId}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'INVITATION_VIEW_FORBIDDEN');

        $this->postJson("/api/v1/team-invitations/{$invitationId}/response", ['response' => 'accepted'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'INVITATION_RESPONSE_FORBIDDEN');
    }

    public function test_the_company_can_read_an_invitation_it_sent(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_SELECTION);

        $this->staff($project, $this->application($project, $this->learner('invitee@test.com')));

        Sanctum::actingAs($owner);
        $invitationId = $this->postJson("/api/v1/projects/{$project->id}/team/invitations")
            ->json('data.invitations.0.id');

        $this->getJson("/api/v1/team-invitations/{$invitationId}")
            ->assertOk()
            ->assertJsonPath('data.id', $invitationId);
    }

    public function test_an_invalid_response_value_is_a_validation_error(): void
    {
        $owner = $this->user('owner@test.com', 'company_admin', $this->organization());
        $project = $this->project($owner, Project::STATUS_SELECTION);

        $student = $this->learner('invitee@test.com');
        $this->staff($project, $this->application($project, $student));

        Sanctum::actingAs($owner);
        $invitationId = $this->postJson("/api/v1/projects/{$project->id}/team/invitations")
            ->json('data.invitations.0.id');

        Sanctum::actingAs($student);

        $this->postJson("/api/v1/team-invitations/{$invitationId}/response", ['response' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_an_unknown_invitation_is_404(): void
    {
        Sanctum::actingAs($this->learner('someone@test.com'));

        $this->getJson('/api/v1/team-invitations/999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'INVITATION_NOT_FOUND');
    }
}
