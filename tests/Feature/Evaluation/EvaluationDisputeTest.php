<?php

namespace Tests\Feature\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationDispute;
use App\Models\EvaluationModerationCase;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\Rubric;
use App\Models\RubricDimension;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EvaluationDisputeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private Project $project;

    private Rubric $rubric;

    private Evaluation $evaluation;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['learner', 'admin', 'company_admin', 'mentor', 'reviewer'] as $slug) {
            Role::create(['name' => ucfirst($slug), 'slug' => $slug, 'description' => '']);
        }

        $organization = Organization::forceCreate([
            'name' => 'Dispute Org',
            'type' => 'company',
            'verification_status' => 'verified',
        ]);

        $this->owner = $this->user('owner@test.com', 'company_admin', $organization);
        $this->member = $this->user('member@test.com', 'learner');

        $this->rubric = Rubric::create([
            'title' => 'Dispute Rubric',
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
            'title' => 'Dispute Project',
            'status' => Project::STATUS_UNDER_REVIEW,
            'confidentiality' => 'public',
            'rubric_id' => $this->rubric->id,
        ]);

        $this->evaluation = $this->finalizedEvaluation();
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

    private function finalizedEvaluation(?Carbon $finalizedAt = null): Evaluation
    {
        return Evaluation::create([
            'project_id' => $this->project->id,
            'participant_id' => $this->member->id,
            'evaluator_id' => $this->owner->id,
            'evaluator_type' => Evaluation::EVALUATOR_TYPE_COMPANY,
            'rubric_id' => $this->rubric->id,
            'rubric_version' => 1,
            'status' => Evaluation::STATUS_FINALIZED,
            'overall_score' => 65.0,
            'team_outcome' => 'Delivered most of the scope.',
            'individual_contribution' => 'Handled the API.',
            'improvement_guidance' => 'Improve test coverage.',
            'submitted_at' => now(),
            'finalized_at' => $finalizedAt ?? now(),
        ]);
    }

    public function test_student_can_dispute_a_finalized_evaluation(): void
    {
        Sanctum::actingAs($this->member);

        $this->postJson("/api/v1/evaluations/{$this->evaluation->id}/disputes", [
            'grounds' => 'The quality score ignores the refactor I delivered in the final week.',
            'disputed_criteria' => ['quality'],
            'evidence' => 'See commit history on the repository.',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.dispute.status', EvaluationDispute::STATUS_PENDING)
            ->assertJsonPath('data.dispute.rubric_version', 1);

        $this->assertSame(Evaluation::STATUS_DISPUTED, $this->evaluation->fresh()->status);
        $this->assertSame(1, EvaluationModerationCase::where('type', EvaluationModerationCase::TYPE_DISPUTE)->count());
    }

    public function test_a_dispute_cannot_be_opened_after_the_window_closes(): void
    {
        $this->evaluation->forceFill(['finalized_at' => now()->subDays(40)])->save();

        Sanctum::actingAs($this->member);

        $this->postJson("/api/v1/evaluations/{$this->evaluation->id}/disputes", [
            'grounds' => 'The quality score ignores the refactor I delivered in the final week.',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DISPUTE_WINDOW_CLOSED');
    }

    public function test_a_second_open_dispute_is_rejected(): void
    {
        Sanctum::actingAs($this->member);

        $payload = [
            'grounds' => 'The quality score ignores the refactor I delivered in the final week.',
        ];

        $this->postJson("/api/v1/evaluations/{$this->evaluation->id}/disputes", $payload)->assertStatus(201);
        $this->postJson("/api/v1/evaluations/{$this->evaluation->id}/disputes", $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'EVALUATION_NOT_FINALIZED');
    }

    public function test_unknown_disputed_criteria_are_rejected(): void
    {
        Sanctum::actingAs($this->member);

        $this->postJson("/api/v1/evaluations/{$this->evaluation->id}/disputes", [
            'grounds' => 'The quality score ignores the refactor I delivered in the final week.',
            'disputed_criteria' => ['punctuality'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DISPUTE_CRITERIA_INVALID');
    }

    public function test_another_user_cannot_dispute_the_evaluation(): void
    {
        $stranger = $this->user('stranger@test.com', 'learner');

        Sanctum::actingAs($stranger);

        $this->postJson("/api/v1/evaluations/{$this->evaluation->id}/disputes", [
            'grounds' => 'The quality score ignores the refactor I delivered in the final week.',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'DISPUTE_FORBIDDEN');
    }

    public function test_a_draft_evaluation_cannot_be_disputed(): void
    {
        $this->evaluation->forceFill(['status' => Evaluation::STATUS_DRAFT])->save();

        Sanctum::actingAs($this->member);

        $this->postJson("/api/v1/evaluations/{$this->evaluation->id}/disputes", [
            'grounds' => 'The quality score ignores the refactor I delivered in the final week.',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'EVALUATION_NOT_FINALIZED');
    }

    public function test_student_sees_their_own_disputes(): void
    {
        Sanctum::actingAs($this->member);

        $this->postJson("/api/v1/evaluations/{$this->evaluation->id}/disputes", [
            'grounds' => 'The quality score ignores the refactor I delivered in the final week.',
        ])->assertStatus(201);

        $this->getJson('/api/v1/learner/disputes')
            ->assertStatus(200)
            ->assertJsonPath('data.disputes.0.evaluation_id', $this->evaluation->id)
            ->assertJsonPath('data.disputes.0.project.id', $this->project->id);
    }
}
