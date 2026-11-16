<?php

namespace Tests\Feature\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationCorrectionRequest;
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
use App\Models\Skill;
use App\Models\SkillEvidence;
use App\Models\StudentProfile;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EvaluationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private Project $project;

    private Rubric $rubric;

    private RubricDimension $quality;

    private RubricDimension $communication;

    private EvaluationTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['learner', 'admin', 'company_admin', 'mentor', 'reviewer'] as $slug) {
            Role::create(['name' => ucfirst($slug), 'slug' => $slug, 'description' => '']);
        }

        $organization = Organization::forceCreate([
            'name' => 'Workflow Org',
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

        $skillA = Skill::create(['name' => 'Code Quality', 'slug' => 'code-quality', 'category' => 'engineering']);
        $skillB = Skill::create(['name' => 'Communication', 'slug' => 'communication', 'category' => 'soft']);

        $this->rubric = Rubric::create([
            'title' => 'Workflow Rubric',
            'version' => 1,
            'status' => Rubric::STATUS_PUBLISHED,
            'score_min' => 0,
            'score_max' => 100,
            'published_at' => now(),
        ]);

        $this->quality = RubricDimension::create([
            'rubric_id' => $this->rubric->id,
            'name' => 'quality',
            'weight' => 0.6,
            'score_min' => 0,
            'score_max' => 100,
            'order_index' => 0,
        ]);

        $this->communication = RubricDimension::create([
            'rubric_id' => $this->rubric->id,
            'name' => 'communication',
            'weight' => 0.4,
            'score_min' => 0,
            'score_max' => 100,
            'order_index' => 1,
        ]);

        $this->quality->skills()->attach($skillA->id);
        $this->communication->skills()->attach($skillB->id);

        $this->project = Project::forceCreate([
            'organization_id' => $organization->id,
            'owner_id' => $this->owner->id,
            'type' => 'company_sponsored',
            'title' => 'Workflow Project',
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
            'link_url' => 'https://github.com/skillspan/workflow',
            'status' => Submission::STATUS_SUBMITTED,
            'is_current' => true,
            'submitted_at' => now(),
        ]);

        $this->task = EvaluationTask::create([
            'project_id' => $this->project->id,
            'participant_id' => $this->member->id,
            'submission_id' => $submission->id,
            'evaluator_id' => $this->owner->id,
            'evaluator_type' => Evaluation::EVALUATOR_TYPE_COMPANY,
            'rubric_id' => $this->rubric->id,
            'status' => EvaluationTask::STATUS_ASSIGNED,
            'assigned_at' => now(),
            'due_at' => now()->addDays(7),
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

    private function draft(): Evaluation
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/evaluations', ['task_id' => $this->task->id])
            ->assertStatus(201)
            ->assertJsonPath('data.evaluation.status', Evaluation::STATUS_DRAFT);

        return Evaluation::findOrFail($response->json('data.evaluation.id'));
    }

    private function scorePayload(float $quality = 80.0, float $communication = 60.0): array
    {
        return [
            'team_outcome' => 'Delivered the agreed scope.',
            'individual_contribution' => 'Owned the API layer.',
            'improvement_guidance' => 'Add more automated tests.',
            'dimension_scores' => [
                [
                    'rubric_dimension_id' => $this->quality->id,
                    'score' => $quality,
                    'comment' => 'Clean and readable implementation.',
                ],
                [
                    'rubric_dimension_id' => $this->communication->id,
                    'score' => $communication,
                    'comment' => 'Documentation is adequate but thin.',
                ],
            ],
        ];
    }

    public function test_evaluator_sees_assigned_tasks(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/evaluations/tasks')
            ->assertStatus(200)
            ->assertJsonPath('data.tasks.0.id', $this->task->id)
            ->assertJsonPath('data.tasks.0.participant.id', $this->member->id)
            ->assertJsonPath('data.tasks.0.rubric.version', 1);
    }

    public function test_evaluator_can_create_and_save_a_draft(): void
    {
        $evaluation = $this->draft();

        $this->patchJson("/api/v1/evaluations/{$evaluation->id}", $this->scorePayload())
            ->assertStatus(200)
            ->assertJsonPath('data.evaluation.criteria.0.score', 80)
            ->assertJsonPath('data.evaluation.criteria.1.score', 60);

        $this->assertSame(Evaluation::STATUS_DRAFT, $evaluation->fresh()->status);
        $this->assertSame(2, $evaluation->dimensionScores()->count());
        $this->assertSame(EvaluationTask::STATUS_IN_PROGRESS, $this->task->fresh()->status);
    }

    public function test_a_score_outside_the_rubric_range_is_rejected(): void
    {
        $evaluation = $this->draft();

        $payload = $this->scorePayload();
        $payload['dimension_scores'][0]['score'] = 140;

        $this->patchJson("/api/v1/evaluations/{$evaluation->id}", $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'EVALUATION_SCORE_OUT_OF_RANGE');
    }

    public function test_submission_is_blocked_until_every_dimension_is_scored(): void
    {
        $evaluation = $this->draft();

        $payload = $this->scorePayload();
        unset($payload['dimension_scores'][1]);

        $this->patchJson("/api/v1/evaluations/{$evaluation->id}", $payload)->assertStatus(200);

        $this->postJson("/api/v1/evaluations/{$evaluation->id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('code', 'EVALUATION_CRITERIA_MISSING');
    }

    public function test_submitting_a_complete_evaluation_finalizes_it_and_creates_skill_evidence(): void
    {
        $evaluation = $this->draft();

        $this->patchJson("/api/v1/evaluations/{$evaluation->id}", $this->scorePayload())->assertStatus(200);

        $this->postJson("/api/v1/evaluations/{$evaluation->id}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.evaluation.status', Evaluation::STATUS_FINALIZED)
            ->assertJsonPath('data.evaluation.overall_score', 72);

        $fresh = $evaluation->fresh();

        $this->assertSame(Evaluation::STATUS_FINALIZED, $fresh->status);
        $this->assertSame($this->owner->id, $fresh->finalized_by);
        $this->assertSame('sum(score * weight) / sum(weight)', $fresh->calculation_inputs['formula']);
        $this->assertSame(2, SkillEvidence::where('source_record_type', 'evaluation')->where('source_record_id', $fresh->id)->count());
        $this->assertSame(EvaluationTask::STATUS_FINALIZED, $this->task->fresh()->status);
    }

    public function test_participant_cannot_view_a_draft_evaluation(): void
    {
        $evaluation = $this->draft();

        Sanctum::actingAs($this->member);

        $this->getJson("/api/v1/evaluations/{$evaluation->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'EVALUATION_NOT_PUBLISHED');
    }

    public function test_participant_can_view_a_finalized_evaluation(): void
    {
        $evaluation = $this->draft();

        $this->patchJson("/api/v1/evaluations/{$evaluation->id}", $this->scorePayload())->assertStatus(200);
        $this->postJson("/api/v1/evaluations/{$evaluation->id}/submit")->assertStatus(200);

        Sanctum::actingAs($this->member);

        $this->getJson("/api/v1/evaluations/{$evaluation->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.evaluation.status', Evaluation::STATUS_FINALIZED)
            ->assertJsonPath('data.evaluation.overall_score', 72)
            ->assertJsonMissingPath('data.evaluation.confidential_notes');
    }

    public function test_declaring_a_conflict_sends_the_evaluation_to_moderation(): void
    {
        $evaluation = $this->draft();

        $this->postJson("/api/v1/evaluations/{$evaluation->id}/conflict", [
            'reason' => 'I previously mentored this participant.',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.evaluation.status', Evaluation::STATUS_MODERATION);

        $this->assertTrue((bool) $evaluation->fresh()->conflict_declared);
        $this->assertSame(1, EvaluationModerationCase::where('type', EvaluationModerationCase::TYPE_CONFLICT)->count());
    }

    public function test_a_finalized_evaluation_cannot_be_edited(): void
    {
        $evaluation = $this->draft();

        $this->patchJson("/api/v1/evaluations/{$evaluation->id}", $this->scorePayload())->assertStatus(200);
        $this->postJson("/api/v1/evaluations/{$evaluation->id}/submit")->assertStatus(200);

        $this->patchJson("/api/v1/evaluations/{$evaluation->id}", $this->scorePayload(90, 90))
            ->assertStatus(422)
            ->assertJsonPath('code', 'EVALUATION_IMMUTABLE');
    }

    public function test_correction_request_requires_a_finalized_evaluation(): void
    {
        $evaluation = $this->draft();

        $this->postJson("/api/v1/evaluations/{$evaluation->id}/corrections", [
            'reason' => 'The communication score looks too low for the submitted work.',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'EVALUATION_NOT_FINALIZED');
    }

    public function test_correction_request_is_recorded_for_a_finalized_evaluation(): void
    {
        $evaluation = $this->draft();

        $this->patchJson("/api/v1/evaluations/{$evaluation->id}", $this->scorePayload())->assertStatus(200);
        $this->postJson("/api/v1/evaluations/{$evaluation->id}/submit")->assertStatus(200);

        $this->postJson("/api/v1/evaluations/{$evaluation->id}/corrections", [
            'reason' => 'The communication score looks too low for the submitted work.',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.correction_request.status', EvaluationCorrectionRequest::STATUS_PENDING);

        $this->assertSame(1, EvaluationCorrectionRequest::where('evaluation_id', $evaluation->id)->count());
    }

    public function test_another_user_cannot_score_someone_elses_evaluation(): void
    {
        $evaluation = $this->draft();
        $stranger = $this->user('stranger@test.com', 'mentor');

        Sanctum::actingAs($stranger);

        $this->patchJson("/api/v1/evaluations/{$evaluation->id}", $this->scorePayload())
            ->assertStatus(403)
            ->assertJsonPath('code', 'EVALUATION_NOT_ASSIGNED');
    }

    public function test_project_evaluations_are_visible_to_the_project_manager(): void
    {
        $this->draft();

        Sanctum::actingAs($this->owner);

        $this->getJson("/api/v1/projects/{$this->project->id}/evaluations")
            ->assertStatus(200)
            ->assertJsonPath('data.evaluations.0.task_id', $this->task->id)
            ->assertJsonPath('data.evaluations.0.evaluator.type', Evaluation::EVALUATOR_TYPE_COMPANY);
    }
}
