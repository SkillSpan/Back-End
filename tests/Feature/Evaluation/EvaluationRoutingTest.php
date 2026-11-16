<?php

namespace Tests\Feature\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationModerationCase;
use App\Models\EvaluationTask;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectTeam;
use App\Models\ProjectTeamMember;
use App\Models\Role;
use App\Models\Rubric;
use App\Models\RubricDimension;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EvaluationRoutingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private Project $project;

    private ProjectMilestone $milestone;

    private Rubric $rubric;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['learner', 'admin', 'company_admin', 'mentor', 'reviewer'] as $slug) {
            Role::create(['name' => ucfirst($slug), 'slug' => $slug, 'description' => '']);
        }

        $organization = Organization::forceCreate([
            'name' => 'Routing Org',
            'type' => 'company',
            'verification_status' => 'verified',
        ]);

        $this->owner = $this->user('owner@test.com', 'company_admin', $organization);
        $this->member = $this->user('member@test.com', 'learner');

        $this->rubric = Rubric::create([
            'title' => 'Routing Rubric',
            'version' => 1,
            'status' => Rubric::STATUS_PUBLISHED,
            'score_min' => 0,
            'score_max' => 100,
            'published_at' => now(),
        ]);

        RubricDimension::create([
            'rubric_id' => $this->rubric->id,
            'name' => 'quality',
            'weight' => 1,
            'score_min' => 0,
            'score_max' => 100,
            'order_index' => 0,
        ]);

        $this->project = $this->projectFor('company_sponsored', $this->owner, $organization);
        $this->milestone = $this->milestoneFor($this->project);
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

    private function projectFor(string $type, User $owner, ?Organization $organization = null): Project
    {
        $project = Project::forceCreate([
            'organization_id' => $organization?->id,
            'owner_id' => $owner->id,
            'type' => $type,
            'title' => 'Routing Project',
            'status' => Project::STATUS_ACTIVE,
            'confidentiality' => 'public',
            'rubric_id' => $this->rubric->id,
        ]);

        $team = ProjectTeam::create(['project_id' => $project->id, 'name' => 'Team']);
        ProjectTeamMember::forceCreate([
            'project_team_id' => $team->id,
            'user_id' => $this->member->id,
            'project_role' => 'developer',
            'assignment_state' => 'active',
        ]);

        return $project;
    }

    private function milestoneFor(Project $project): ProjectMilestone
    {
        return ProjectMilestone::create([
            'project_id' => $project->id,
            'title' => 'Final milestone',
            'required_deliverables' => ['Repository link'],
            'submission_type' => 'any',
            'due_date' => now()->addDays(3)->toDateString(),
            'effective_deadline' => now()->addDays(3)->toDateString(),
            'status' => ProjectMilestone::STATUS_PENDING,
            'order_index' => 0,
        ]);
    }

    private function submit(Project $project, ProjectMilestone $milestone): Submission
    {
        return Submission::create([
            'project_id' => $project->id,
            'project_milestone_id' => $milestone->id,
            'contributor_id' => $this->member->id,
            'version' => 1,
            'submission_type' => 'repository_link',
            'link_url' => 'https://github.com/skillspan/routing',
            'status' => Submission::STATUS_SUBMITTED,
            'is_current' => true,
            'submitted_at' => now(),
        ]);
    }

    public function test_entering_review_is_blocked_while_a_milestone_has_no_submission(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/projects/{$this->project->id}/transitions", ['to' => 'under_review'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_DELIVERABLES_PENDING')
            ->assertJsonPath('details.missing_milestones.0.title', 'Final milestone');
    }

    public function test_entering_review_routes_the_company_evaluator(): void
    {
        Sanctum::actingAs($this->owner);
        $this->submit($this->project, $this->milestone);

        $this->postJson("/api/v1/projects/{$this->project->id}/transitions", ['to' => 'under_review'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', Project::STATUS_UNDER_REVIEW);

        $task = EvaluationTask::where('project_id', $this->project->id)->first();

        $this->assertNotNull($task);
        $this->assertSame($this->owner->id, $task->evaluator_id);
        $this->assertSame(Evaluation::EVALUATOR_TYPE_COMPANY, $task->evaluator_type);
        $this->assertSame(EvaluationTask::STATUS_ASSIGNED, $task->status);
    }

    public function test_a_simulation_project_routes_to_its_mentor(): void
    {
        $mentor = $this->user('mentor@test.com', 'mentor');
        $project = $this->projectFor('simulation', $mentor);
        $milestone = $this->milestoneFor($project);
        $this->submit($project, $milestone);

        Sanctum::actingAs($mentor);

        $this->postJson("/api/v1/projects/{$project->id}/transitions", ['to' => 'under_review'])->assertStatus(200);

        $task = EvaluationTask::where('project_id', $project->id)->first();

        $this->assertSame($mentor->id, $task->evaluator_id);
        $this->assertSame(Evaluation::EVALUATOR_TYPE_MENTOR, $task->evaluator_type);
    }

    public function test_an_unroutable_submission_goes_to_the_moderation_queue(): void
    {
        $admin = $this->user('admin@test.com', 'admin');
        $project = $this->projectFor('simulation', $admin);
        $milestone = $this->milestoneFor($project);
        $this->submit($project, $milestone);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/projects/{$project->id}/transitions", ['to' => 'under_review'])->assertStatus(200);

        $task = EvaluationTask::where('project_id', $project->id)->first();

        $this->assertSame(EvaluationTask::STATUS_UNROUTABLE, $task->status);
        $this->assertNull($task->evaluator_id);
        $this->assertSame(1, EvaluationModerationCase::where('status', 'open')->count());
    }

    public function test_an_evaluator_with_a_declared_conflict_is_skipped(): void
    {
        Evaluation::create([
            'project_id' => $this->project->id,
            'evaluator_id' => $this->owner->id,
            'rubric_id' => $this->rubric->id,
            'rubric_version' => 1,
            'status' => Evaluation::STATUS_DRAFT,
            'conflict_declared' => true,
        ]);

        Sanctum::actingAs($this->owner);
        $this->submit($this->project, $this->milestone);

        $this->postJson("/api/v1/projects/{$this->project->id}/transitions", ['to' => 'under_review'])->assertStatus(200);

        $task = EvaluationTask::where('project_id', $this->project->id)->first();

        $this->assertSame(EvaluationTask::STATUS_UNROUTABLE, $task->status);
    }

    public function test_completing_a_project_is_blocked_while_evaluations_are_pending(): void
    {
        Sanctum::actingAs($this->owner);
        $this->submit($this->project, $this->milestone);

        $this->project->forceFill(['status' => Project::STATUS_UNDER_REVIEW])->save();

        $this->postJson("/api/v1/projects/{$this->project->id}/transitions", ['to' => 'completed'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROJECT_EVALUATIONS_PENDING');
    }
}
