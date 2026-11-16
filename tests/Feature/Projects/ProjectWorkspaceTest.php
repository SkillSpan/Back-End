<?php

namespace Tests\Feature\Projects;

use App\Models\Notification;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectTeam;
use App\Models\ProjectTeamMember;
use App\Models\Role;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $member;

    private User $outsider;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['learner', 'admin', 'company_admin'] as $slug) {
            Role::create(['name' => ucfirst($slug), 'slug' => $slug, 'description' => '']);
        }

        $this->organization = Organization::forceCreate([
            'name' => 'Workspace Org',
            'type' => 'company',
            'verification_status' => 'verified',
        ]);

        $this->owner = $this->user('owner@test.com', 'company_admin', $this->organization);
        $this->member = $this->user('member@test.com', 'learner');
        $this->outsider = $this->user('outsider@test.com', 'learner');

        $this->project = Project::forceCreate([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->owner->id,
            'type' => 'company_sponsored',
            'title' => 'Delivery Platform',
            'description' => 'Build a delivery platform.',
            'objectives' => 'Ship a working product.',
            'learning_outcomes' => ['api design'],
            'status' => Project::STATUS_ACTIVE,
            'confidentiality' => 'public',
        ]);

        $team = ProjectTeam::create(['project_id' => $this->project->id, 'name' => 'Delivery Team']);

        ProjectTeamMember::forceCreate([
            'project_team_id' => $team->id,
            'user_id' => $this->member->id,
            'project_role' => 'backend_developer',
            'assignment_state' => 'active',
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
            $user->organizations()->attach($organization->id, ['role_in_org' => 'admin', 'status' => 'active']);
        }

        return $user->fresh();
    }

    private function milestone(array $overrides = []): ProjectMilestone
    {
        return ProjectMilestone::create(array_merge([
            'project_id' => $this->project->id,
            'title' => 'Milestone one',
            'description' => 'First delivery',
            'required_deliverables' => ['Repository link'],
            'submission_type' => 'repository_link',
            'due_date' => now()->addDays(5)->toDateString(),
            'effective_deadline' => now()->addDays(5)->toDateString(),
            'status' => ProjectMilestone::STATUS_PENDING,
            'order_index' => 0,
        ], $overrides));
    }

    public function test_a_project_member_opens_the_workspace(): void
    {
        Sanctum::actingAs($this->member);
        $this->milestone();

        $response = $this->getJson("/api/v1/projects/{$this->project->id}/workspace");

        $response->assertStatus(200)
            ->assertJsonPath('data.project.id', $this->project->id)
            ->assertJsonPath('data.project.type', 'company_sponsored')
            ->assertJsonPath('data.viewer.is_member', true)
            ->assertJsonPath('data.viewer.can_submit', true)
            ->assertJsonCount(1, 'data.milestones')
            ->assertJsonCount(1, 'data.team')
            ->assertJsonPath('data.guidance.available', true);

        $this->assertNotEmpty($response->json('data.milestones.0.countdown.label'));
    }

    public function test_a_non_member_is_denied_the_workspace(): void
    {
        Sanctum::actingAs($this->outsider);

        $this->getJson("/api/v1/projects/{$this->project->id}/workspace")
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_WORKSPACE_FORBIDDEN');
    }

    public function test_the_project_owner_creates_an_ordered_milestone_and_notifies_the_team(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/projects/{$this->project->id}/milestones", [
            'title' => 'Sprint one delivery',
            'required_deliverables' => ['Repository link', 'Demo recording'],
            'submission_type' => 'repository_link',
            'due_date' => now()->addDays(7)->toDateString(),
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.milestone.status', ProjectMilestone::STATUS_PENDING)
            ->assertJsonPath('data.milestone.required_deliverables.1', 'Demo recording');

        $this->assertDatabaseHas('project_milestones', [
            'project_id' => $this->project->id,
            'title' => 'Sprint one delivery',
        ]);

        $this->assertSame(1, Notification::where('user_id', $this->member->id)->count());
    }

    public function test_an_unauthorized_representative_cannot_create_milestones(): void
    {
        Sanctum::actingAs($this->outsider);

        $this->postJson("/api/v1/projects/{$this->project->id}/milestones", [
            'title' => 'Illegal milestone',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_NOT_OWNED');
    }

    public function test_updating_a_milestone_records_the_change_history(): void
    {
        Sanctum::actingAs($this->owner);
        $milestone = $this->milestone();

        $this->patchJson("/api/v1/milestones/{$milestone->id}", [
            'title' => 'Milestone one revised',
            'due_date' => now()->addDays(9)->toDateString(),
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.milestone.title', 'Milestone one revised');

        $this->assertDatabaseHas('project_milestone_changes', [
            'project_milestone_id' => $milestone->id,
            'field' => 'title',
            'new_value' => 'Milestone one revised',
        ]);
    }

    public function test_updating_a_milestone_never_removes_existing_submissions(): void
    {
        Sanctum::actingAs($this->owner);
        $milestone = $this->milestone();

        Submission::create([
            'project_id' => $this->project->id,
            'project_milestone_id' => $milestone->id,
            'contributor_id' => $this->member->id,
            'version' => 1,
            'submission_type' => 'repository_link',
            'link_url' => 'https://github.com/example/repo',
            'status' => Submission::STATUS_SUBMITTED,
            'is_current' => true,
            'submitted_at' => now(),
        ]);

        $this->patchJson("/api/v1/milestones/{$milestone->id}", ['title' => 'Renamed milestone'])->assertStatus(200);

        $this->assertDatabaseCount('submissions', 1);
    }

    public function test_a_member_requests_an_extension_before_the_cutoff(): void
    {
        Sanctum::actingAs($this->member);
        $milestone = $this->milestone();

        $this->postJson("/api/v1/milestones/{$milestone->id}/extension-requests", [
            'reason' => 'The client changed the requirements mid sprint.',
            'new_due_date' => now()->addDays(10)->toDateString(),
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.extension_request.status', 'pending');

        $this->assertSame(
            $milestone->due_date->toDateString(),
            $milestone->fresh()->effective_deadline->toDateString(),
            'The effective deadline must not change before an approval.'
        );

        $this->assertSame(1, Notification::where('user_id', $this->owner->id)->count());
    }

    public function test_an_extension_request_after_the_cutoff_is_rejected(): void
    {
        Sanctum::actingAs($this->member);
        $milestone = $this->milestone([
            'due_date' => now()->subDays(3)->toDateString(),
            'effective_deadline' => now()->subDays(3)->toDateString(),
        ]);

        $this->postJson("/api/v1/milestones/{$milestone->id}/extension-requests", [
            'reason' => 'I was busy with another course.',
            'new_due_date' => now()->addDays(2)->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'EXTENSION_CUTOFF_PASSED');
    }

    public function test_only_one_pending_extension_request_is_allowed_per_milestone(): void
    {
        Sanctum::actingAs($this->member);
        $milestone = $this->milestone();

        $payload = [
            'reason' => 'Need more time for the final polish.',
            'new_due_date' => now()->addDays(12)->toDateString(),
        ];

        $this->postJson("/api/v1/milestones/{$milestone->id}/extension-requests", $payload)->assertStatus(201);

        $this->postJson("/api/v1/milestones/{$milestone->id}/extension-requests", $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'EXTENSION_ALREADY_PENDING');
    }

    public function test_approving_an_extension_updates_the_effective_deadline(): void
    {
        Sanctum::actingAs($this->member);
        $milestone = $this->milestone();

        $extensionId = $this->postJson("/api/v1/milestones/{$milestone->id}/extension-requests", [
            'reason' => 'Waiting on a third party integration.',
            'new_due_date' => now()->addDays(15)->toDateString(),
        ])->assertStatus(201)->json('data.extension_request.id');

        Sanctum::actingAs($this->owner);

        $this->patchJson("/api/v1/extension-requests/{$extensionId}", [
            'decision' => 'approved',
            'reason' => 'Reasonable request.',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.extension_request.status', 'approved')
            ->assertJsonPath('data.extension_request.applied_deadline', now()->addDays(15)->toDateString());

        $this->assertSame(now()->addDays(15)->toDateString(), $milestone->fresh()->effective_deadline->toDateString());
    }

    public function test_rejecting_an_extension_keeps_the_effective_deadline(): void
    {
        Sanctum::actingAs($this->member);
        $milestone = $this->milestone();
        $original = $milestone->effective_deadline->toDateString();

        $extensionId = $this->postJson("/api/v1/milestones/{$milestone->id}/extension-requests", [
            'reason' => 'Personal reasons that cannot be shared.',
            'new_due_date' => now()->addDays(20)->toDateString(),
        ])->assertStatus(201)->json('data.extension_request.id');

        Sanctum::actingAs($this->owner);

        $this->patchJson("/api/v1/extension-requests/{$extensionId}", [
            'decision' => 'rejected',
            'reason' => 'The project closes on the original date.',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.extension_request.status', 'rejected');

        $this->assertSame($original, $milestone->fresh()->effective_deadline->toDateString());
    }

    public function test_the_owner_lists_pending_extension_requests(): void
    {
        Sanctum::actingAs($this->member);
        $milestone = $this->milestone();

        $this->postJson("/api/v1/milestones/{$milestone->id}/extension-requests", [
            'reason' => 'Blocked by an upstream dependency.',
            'new_due_date' => now()->addDays(8)->toDateString(),
        ])->assertStatus(201);

        Sanctum::actingAs($this->owner);

        $this->getJson("/api/v1/projects/{$this->project->id}/extension-requests")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.extension_requests')
            ->assertJsonPath('data.extension_requests.0.status', 'pending');
    }
}
