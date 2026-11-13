<?php

namespace Tests\Feature\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationTask;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectTeam;
use App\Models\ProjectTeamMember;
use App\Models\Role;
use App\Models\Rubric;
use App\Models\RubricDimension;
use App\Models\SelfReflection;
use App\Models\StudentProfile;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SelfReflectionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private Project $project;

    private Rubric $rubric;

    private RubricDimension $quality;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['learner', 'admin', 'company_admin', 'mentor', 'reviewer'] as $slug) {
            Role::create(['name' => ucfirst($slug), 'slug' => $slug, 'description' => '']);
        }

        $organization = Organization::forceCreate([
            'name' => 'Reflection Org',
            'type' => 'company',
            'verification_status' => 'verified',
        ]);

        $this->owner = $this->user('owner@test.com', 'company_admin', $organization);
        $this->member = $this->user('member@test.com', 'learner');

        StudentProfile::forceCreate([
            'user_id' => $this->member->id,
            'specialization' => 'software_engineering',
            'career_status' => 'student',
        ]);

        $this->rubric = Rubric::create([
            'title' => 'Reflection Rubric',
            'version' => 1,
            'status' => Rubric::STATUS_PUBLISHED,
            'score_min' => 0,
            'score_max' => 100,
            'published_at' => now(),
        ]);

        $this->quality = RubricDimension::create([
            'rubric_id' => $this->rubric->id,
            'name' => 'quality',
            'weight' => 1,
            'score_min' => 0,
            'score_max' => 100,
            'order_index' => 0,
        ]);

        $this->project = Project::forceCreate([
            'organization_id' => $organization->id,
            'owner_id' => $this->owner->id,
            'type' => 'company_sponsored',
            'title' => 'Reflection Project',
            'status' => Project::STATUS_UNDER_REVIEW,
            'confidentiality' => 'public',
            'rubric_id' => $this->rubric->id,
        ]);

        $team = ProjectTeam::create(['project_id' => $this->project->id, 'name' => 'Team']);
        ProjectTeamMember::forceCreate([
            'project_team_id' => $team->id,
            'user_id' => $this->member->id,
            'project_role' => 'developer',
            'assignment_state' => 'active',
        ]);

        $milestone = ProjectMilestone::create([
            'project_id' => $this->project->id,
            'title' => 'Final milestone',
            'required_deliverables' => ['Repository link'],
            'submission_type' => 'any',
            'due_date' => now()->addDays(3)->toDateString(),
            'effective_deadline' => now()->addDays(3)->toDateString(),
            'status' => ProjectMilestone::STATUS_PENDING,
            'order_index' => 0,
        ]);

        $submission = Submission::create([
            'project_id' => $this->project->id,
            'project_milestone_id' => $milestone->id,
            'contributor_id' => $this->member->id,
            'version' => 1,
            'submission_type' => 'repository_link',
            'link_url' => 'https://github.com/skillspan/reflection',
            'status' => Submission::STATUS_SUBMITTED,
            'is_current' => true,
            'submitted_at' => now(),
        ]);

        EvaluationTask::create([
            'project_id' => $this->project->id,
            'participant_id' => $this->member->id,
            'submission_id' => $submission->id,
            'evaluator_id' => $this->owner->id,
            'evaluator_type' => Evaluation::EVALUATOR_TYPE_COMPANY,
            'rubric_id' => $this->rubric->id,
            'status' => EvaluationTask::STATUS_FINALIZED,
            'assigned_at' => now(),
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

    private function finalizedEvaluation(): Evaluation
    {
        return Evaluation::create([
            'project_id' => $this->project->id,
            'participant_id' => $this->member->id,
            'evaluator_id' => $this->owner->id,
            'evaluator_type' => Evaluation::EVALUATOR_TYPE_COMPANY,
            'rubric_id' => $this->rubric->id,
            'rubric_version' => 1,
            'status' => Evaluation::STATUS_FINALIZED,
            'overall_score' => 78.5,
            'team_outcome' => 'Shipped the agreed scope.',
            'individual_contribution' => 'Owned the backend.',
            'improvement_guidance' => 'Document the API surface.',
            'submitted_at' => now(),
            'finalized_at' => now(),
        ]);
    }

    public function test_student_can_save_and_read_a_reflection_draft(): void
    {
        Sanctum::actingAs($this->member);

        $this->postJson("/api/v1/projects/{$this->project->id}/self-reflection", [
            'answers' => [
                ['question' => 'What went well?', 'response' => 'The API design held up.'],
            ],
            'learning_summary' => 'I learned to scope work earlier.',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.self_reflection.is_submitted', false)
            ->assertJsonPath('data.self_reflection.answers.0.question', 'What went well?');

        $this->getJson("/api/v1/projects/{$this->project->id}/self-reflection")
            ->assertStatus(200)
            ->assertJsonPath('data.self_reflection.learning_summary', 'I learned to scope work earlier.');
    }

    public function test_reflection_submit_is_blocked_until_feedback_is_finalized(): void
    {
        Sanctum::actingAs($this->member);

        $this->postJson("/api/v1/projects/{$this->project->id}/self-reflection", [
            'learning_summary' => 'I learned to scope work earlier.',
        ])->assertStatus(200);

        $this->postJson("/api/v1/projects/{$this->project->id}/self-reflection/submit")
            ->assertStatus(422)
            ->assertJsonPath('code', 'REFLECTION_FEEDBACK_PENDING');
    }

    public function test_reflection_submit_succeeds_after_finalization_and_then_locks(): void
    {
        $this->finalizedEvaluation();

        Sanctum::actingAs($this->member);

        $this->postJson("/api/v1/projects/{$this->project->id}/self-reflection", [
            'learning_summary' => 'I learned to scope work earlier.',
        ])->assertStatus(200);

        $this->postJson("/api/v1/projects/{$this->project->id}/self-reflection/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.self_reflection.is_submitted', true);

        $this->assertNotNull(SelfReflection::where('project_id', $this->project->id)->first()->submitted_at);

        $this->postJson("/api/v1/projects/{$this->project->id}/self-reflection", [
            'learning_summary' => 'Edited after submit.',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'REFLECTION_IMMUTABLE');
    }

    public function test_a_non_member_cannot_write_a_reflection(): void
    {
        $stranger = $this->user('stranger@test.com', 'learner');

        Sanctum::actingAs($stranger);

        $this->postJson("/api/v1/projects/{$this->project->id}/self-reflection", [
            'learning_summary' => 'Not my project.',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'PROJECT_WORKSPACE_FORBIDDEN');
    }

    public function test_student_results_list_shows_finalized_feedback(): void
    {
        $this->finalizedEvaluation();

        Sanctum::actingAs($this->member);

        $this->getJson('/api/v1/learner/evaluations')
            ->assertStatus(200)
            ->assertJsonPath('data.results.0.project.id', $this->project->id)
            ->assertJsonPath('data.results.0.overall_score', 78.5)
            ->assertJsonPath('data.results.0.can_dispute', true)
            ->assertJsonPath('data.results.0.has_open_dispute', false);
    }
}
