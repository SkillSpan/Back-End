<?php

namespace Tests\Feature\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationDispute;
use App\Models\EvaluationException as EvaluationExceptionRecord;
use App\Models\EvaluationModerationCase;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\Rubric;
use App\Models\RubricDimension;
use App\Models\Skill;
use App\Models\SkillEvidence;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModerationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private User $member;

    private Project $project;

    private Rubric $rubric;

    private Evaluation $evaluation;

    private EvaluationDispute $dispute;

    private EvaluationModerationCase $case;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['learner', 'admin', 'company_admin', 'mentor', 'reviewer'] as $slug) {
            Role::create(['name' => ucfirst($slug), 'slug' => $slug, 'description' => '']);
        }

        $organization = Organization::forceCreate([
            'name' => 'Moderation Org',
            'type' => 'company',
            'verification_status' => 'verified',
        ]);

        $this->admin = $this->user('admin@test.com', 'admin');
        $this->owner = $this->user('owner@test.com', 'company_admin', $organization);
        $this->member = $this->user('member@test.com', 'learner');

        StudentProfile::forceCreate([
            'user_id' => $this->member->id,
            'specialization' => 'software_engineering',
            'career_status' => 'student',
        ]);

        $this->rubric = Rubric::create([
            'title' => 'Moderation Rubric',
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

        $this->project = Project::forceCreate([
            'organization_id' => $organization->id,
            'owner_id' => $this->owner->id,
            'type' => 'company_sponsored',
            'title' => 'Moderation Project',
            'status' => Project::STATUS_UNDER_REVIEW,
            'confidentiality' => 'public',
            'rubric_id' => $this->rubric->id,
        ]);

        $this->evaluation = Evaluation::create([
            'project_id' => $this->project->id,
            'participant_id' => $this->member->id,
            'evaluator_id' => $this->owner->id,
            'evaluator_type' => Evaluation::EVALUATOR_TYPE_COMPANY,
            'rubric_id' => $this->rubric->id,
            'rubric_version' => 1,
            'status' => Evaluation::STATUS_DISPUTED,
            'overall_score' => 60.0,
            'team_outcome' => 'Delivered the scope.',
            'individual_contribution' => 'Backend work.',
            'improvement_guidance' => 'Improve coverage.',
            'submitted_at' => now(),
            'finalized_at' => now(),
        ]);

        $skill = Skill::create(['name' => 'Code Quality', 'slug' => 'code-quality', 'category' => 'engineering']);

        SkillEvidence::create([
            'student_profile_id' => StudentProfile::where('user_id', $this->member->id)->first()->id,
            'skill_id' => $skill->id,
            'source' => 'project_performance',
            'evidence_type' => 'document',
            'value' => 3,
            'normalized_value' => 3,
            'reference' => '/projects/'.$this->project->id,
            'description' => 'From evaluation.',
            'evidence_date' => now()->toDateString(),
            'verification_status' => SkillEvidence::STATUS_VERIFIED,
            'reviewer_id' => $this->owner->id,
            'source_record_type' => 'evaluation',
            'source_record_id' => $this->evaluation->id,
        ]);

        $this->dispute = EvaluationDispute::create([
            'evaluation_id' => $this->evaluation->id,
            'submitted_by' => $this->member->id,
            'grounds' => 'The quality score ignores the refactor delivered in the final week.',
            'disputed_criteria' => ['quality'],
            'rubric_version' => 1,
            'status' => EvaluationDispute::STATUS_PENDING,
        ]);

        $this->case = EvaluationModerationCase::create([
            'type' => EvaluationModerationCase::TYPE_DISPUTE,
            'evaluation_id' => $this->evaluation->id,
            'dispute_id' => $this->dispute->id,
            'status' => EvaluationModerationCase::STATUS_OPEN,
            'reason' => 'student_dispute',
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

    public function test_admin_sees_the_open_moderation_queue(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/admin/moderation-cases')
            ->assertStatus(200)
            ->assertJsonPath('data.cases.0.id', $this->case->id)
            ->assertJsonPath('data.cases.0.type', EvaluationModerationCase::TYPE_DISPUTE)
            ->assertJsonPath('data.cases.0.evaluation.id', $this->evaluation->id);
    }

    public function test_a_non_admin_cannot_open_the_moderation_queue(): void
    {
        Sanctum::actingAs($this->member);

        $this->getJson('/api/v1/admin/moderation-cases')->assertStatus(403);
    }

    public function test_a_moderator_cannot_decide_on_their_own_evaluation(): void
    {
        $this->owner->roles()->attach(Role::where('slug', 'admin')->first()->id);

        Sanctum::actingAs($this->owner->fresh());

        $this->postJson("/api/v1/admin/moderation-cases/{$this->case->id}/decide", [
            'decision' => EvaluationModerationCase::DECISION_PRESERVE,
            'reason' => 'Nothing wrong with the score in my opinion.',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'MODERATION_SELF_FORBIDDEN');
    }

    public function test_preserve_restores_the_evaluation_and_rejects_the_dispute(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/admin/moderation-cases/{$this->case->id}/decide", [
            'decision' => EvaluationModerationCase::DECISION_PRESERVE,
            'reason' => 'The score matches the submitted evidence.',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.case.status', EvaluationModerationCase::STATUS_RESOLVED)
            ->assertJsonPath('data.case.decision', EvaluationModerationCase::DECISION_PRESERVE);

        $this->assertSame(Evaluation::STATUS_FINALIZED, $this->evaluation->fresh()->status);
        $this->assertSame(EvaluationDispute::STATUS_REJECTED, $this->dispute->fresh()->status);
        $this->assertSame(1, SkillEvidence::where('source_record_type', 'evaluation')->count());
    }

    public function test_replace_supersedes_the_evaluation_and_purges_evidence(): void
    {
        $reviewer = $this->user('reviewer@test.com', 'reviewer');

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/admin/moderation-cases/{$this->case->id}/decide", [
            'decision' => EvaluationModerationCase::DECISION_REPLACE,
            'reason' => 'The original evaluation missed the final deliverable.',
            'replacement_evaluator_id' => $reviewer->id,
            'classification' => 'data_error',
        ])->assertStatus(200);

        $original = $this->evaluation->fresh();
        $replacement = Evaluation::find($original->superseded_by_evaluation_id);

        $this->assertSame(Evaluation::STATUS_SUPERSEDED, $original->status);
        $this->assertNotNull($replacement);
        $this->assertSame(Evaluation::STATUS_DRAFT, $replacement->status);
        $this->assertSame($reviewer->id, $replacement->evaluator_id);
        $this->assertSame($original->id, $replacement->supersedes_evaluation_id);
        $this->assertSame(0, SkillEvidence::where('source_record_type', 'evaluation')->count());
        $this->assertSame(EvaluationDispute::STATUS_RESOLVED, $this->dispute->fresh()->status);
        $this->assertSame('supersede_decision', $this->dispute->fresh()->corrective_action);
    }

    public function test_void_voids_the_evaluation_and_purges_evidence(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/admin/moderation-cases/{$this->case->id}/decide", [
            'decision' => EvaluationModerationCase::DECISION_VOID,
            'reason' => 'The evaluator had an undisclosed conflict of interest.',
            'classification' => 'abuse',
        ])->assertStatus(200);

        $this->assertSame(Evaluation::STATUS_VOIDED, $this->evaluation->fresh()->status);
        $this->assertSame(0, SkillEvidence::where('source_record_type', 'evaluation')->count());
    }

    public function test_an_unknown_decision_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/admin/moderation-cases/{$this->case->id}/decide", [
            'decision' => 'ignore',
            'reason' => 'Trying an unsupported decision value.',
        ])->assertStatus(422);
    }

    public function test_a_resolved_case_cannot_be_decided_twice(): void
    {
        Sanctum::actingAs($this->admin);

        $payload = [
            'decision' => EvaluationModerationCase::DECISION_PRESERVE,
            'reason' => 'The score matches the submitted evidence.',
        ];

        $this->postJson("/api/v1/admin/moderation-cases/{$this->case->id}/decide", $payload)->assertStatus(200);
        $this->postJson("/api/v1/admin/moderation-cases/{$this->case->id}/decide", $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'MODERATION_CASE_CLOSED');
    }

    public function test_an_exception_can_be_recorded_and_listed(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/admin/evaluation-exceptions', [
            'type' => EvaluationExceptionRecord::TYPE_LATE_DISPUTE,
            'subject_type' => 'evaluation',
            'subject_id' => $this->evaluation->id,
            'actor_id' => $this->member->id,
            'reason' => 'The participant was hospitalised during the dispute window.',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.exception.type', EvaluationExceptionRecord::TYPE_LATE_DISPUTE);

        $this->getJson('/api/v1/admin/evaluation-exceptions?type=late_dispute')
            ->assertStatus(200)
            ->assertJsonPath('data.exceptions.0.subject_id', $this->evaluation->id);
    }
}
