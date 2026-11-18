<?php

namespace Tests\Feature\Assessment;

use App\Events\SkillDataChanged;
use App\Models\BaselineAssessment;
use App\Models\CareerRole;
use App\Models\LearnerSkill;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\SkillEvidence;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Readiness\CompositeReadinessInputs;
use App\Services\Skills\SkillEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BaselineEvidenceAggregationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([SkillDataChanged::class]);
        config(['services.evidence.source_weights' => [
            'self_assessment' => .10, 'assessment_test' => .30,
            'project_performance' => .35, 'expert_evaluation' => .20, 'certificate' => .05,
        ]]);
    }

    private function scenario(): array
    {
        $user = User::factory()->create();
        $profile = StudentProfile::forceCreate(['user_id' => $user->id]);
        $role = CareerRole::forceCreate(['title' => 'Analyst', 'slug' => 'analyst-'.$user->id,
            'version' => 1, 'status' => 'approved']);
        $skill = Skill::firstOrCreate(['slug' => 'sql'], ['name' => 'SQL', 'status' => 'active']);

        return [$profile, $role, $skill];
    }

    private function attempt($profile, $role, $skill, float $level, string $version = 'v1.1-data-analyst'): array
    {
        $attempt = BaselineAssessment::forceCreate([
            'student_profile_id' => $profile->id, 'career_role_id' => $role->id,
            'assessment_type' => 'baseline', 'assessment_version' => $version,
            'status' => 'completed', 'question_count' => 5, 'completed_at' => now(),
            'normalized_skills' => [['skill_id' => $skill->id, 'level' => $level, 'confidence' => 100]],
        ]);
        $evidence = SkillEvidence::forceCreate([
            'student_profile_id' => $profile->id, 'skill_id' => $skill->id,
            'source' => 'assessment_test', 'value' => $level, 'normalized_value' => $level,
            'evidence_date' => now()->toDateString(), 'verification_status' => 'verified',
            'source_record_type' => BaselineAssessment::class, 'source_record_id' => $attempt->id,
        ]);

        return [$attempt, $evidence];
    }

    public function test_answer_coverage_is_preserved_but_evidence_confidence_is_30(): void
    {
        [$profile, $role, $skill] = $this->scenario();
        [$attempt] = $this->attempt($profile, $role, $skill, 2);
        $evaluation = app(SkillEvaluationService::class)->recalculate($profile, $skill, false);
        $this->assertEquals(2, $evaluation->level);
        $this->assertEquals(30, $evaluation->confidence);
        $this->assertSame('skill-confidence-v2-baseline', $evaluation->algorithm_version);
        $this->assertEquals(100, $evaluation->snapshot['baseline_answer_coverage']);
        $this->assertEquals(100, $attempt->fresh()->normalized_skills[0]['confidence']);
        $this->assertEquals(30, app(CompositeReadinessInputs::class)
            ->build($profile, collect([$evaluation]))['assessment_reliability']);
    }

    public function test_latest_completed_attempt_supersedes_old_score_without_deleting_history(): void
    {
        [$profile, $role, $skill] = $this->scenario();
        [$old] = $this->attempt($profile, $role, $skill, 5, 'v1.0');
        [$new] = $this->attempt($profile, $role, $skill, 1);
        $evaluation = app(SkillEvaluationService::class)->recalculate($profile, $skill, false);
        $this->assertEquals(1, $evaluation->level);
        $this->assertEquals(30, $evaluation->confidence);
        $this->assertEquals($new->id, $evaluation->snapshot['baseline_assessment_id']);
        $this->assertSame(2, BaselineAssessment::count());
        $this->assertSame(2, SkillEvidence::count());
        $this->assertEquals(5, $old->fresh()->normalized_skills[0]['level']);
    }

    public function test_other_verified_sources_contribute_and_stored_self_declaration_survives(): void
    {
        [$profile, $role, $skill] = $this->scenario();
        $this->attempt($profile, $role, $skill, 2);
        $self = LearnerSkill::create(['learner_id' => $profile->user_id, 'skill_id' => $skill->id,
            'level' => 1, 'confidence_score' => 10, 'source_type' => 'self']);
        SkillEvidence::forceCreate(['student_profile_id' => $profile->id, 'skill_id' => $skill->id,
            'source' => 'expert_evaluation', 'value' => 4, 'normalized_value' => 4,
            'verification_status' => 'verified', 'evidence_date' => now()->toDateString()]);
        $evaluation = app(SkillEvaluationService::class)->recalculate($profile, $skill, false);
        // (.30 * 2 + .20 * 4) / .50 = 2.8; independent source support = 50/100.
        $this->assertEquals(2.8, $evaluation->level);
        $this->assertEquals(50, $evaluation->confidence);
        $this->assertEquals(1, $self->fresh()->level);
        $this->assertSame('self', $self->fresh()->source_type);
    }

    public function test_revoked_latest_attempt_does_not_restore_an_older_attempt(): void
    {
        [$profile, $role, $skill] = $this->scenario();
        $this->attempt($profile, $role, $skill, 5, 'v1.0');
        [, $latest] = $this->attempt($profile, $role, $skill, 2);
        $latest->update(['verification_status' => 'rejected']);
        $evaluation = app(SkillEvaluationService::class)->recalculate($profile, $skill, false);
        $this->assertEquals(0, $evaluation->level);
        $this->assertEquals(0, $evaluation->confidence);
        $this->assertSame(2, SkillEvidence::count());
    }

    public function test_rebuild_is_preview_by_default_and_apply_is_scoped(): void
    {
        [$profile, $role, $skill] = $this->scenario();
        $this->attempt($profile, $role, $skill, 2);
        [$other, $otherRole] = $this->scenario();
        $this->attempt($other, $otherRole, $skill, 5);
        $legacy = SkillEvaluation::forceCreate(['student_profile_id' => $profile->id,
            'skill_id' => $skill->id, 'level' => 2, 'confidence' => 100,
            'algorithm_version' => 'baseline-v2.0', 'calculated_at' => now(), 'snapshot' => []]);
        $this->artisan('skills:rebuild-baseline-evidence', ['profile' => $profile->id])->assertSuccessful();
        $this->assertSame(1, SkillEvaluation::count());
        $this->artisan('skills:rebuild-baseline-evidence', ['profile' => $profile->id, '--apply' => true])->assertSuccessful();
        $this->assertSame(2, SkillEvaluation::count());
        $this->assertSame(0, SkillEvaluation::where('student_profile_id', $other->id)->count());
        $this->assertEquals(100, $legacy->fresh()->confidence);
        $this->assertSame(2, SkillEvidence::count());
        Event::assertDispatched(SkillDataChanged::class);
    }
}
