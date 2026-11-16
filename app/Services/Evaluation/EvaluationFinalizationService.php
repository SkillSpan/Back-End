<?php

namespace App\Services\Evaluation;

use App\Events\EvaluationFinalized;
use App\Events\SkillDataChanged;
use App\Exceptions\EvaluationException;
use App\Models\Evaluation;
use App\Models\EvaluationModerationCase;
use App\Models\EvaluationTask;
use App\Models\RubricDimension;
use App\Models\SkillEvidence;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\NotificationService;
use App\Traits\AuditsActions;
use Illuminate\Support\Facades\DB;

class EvaluationFinalizationService
{
    use AuditsActions;

    public function __construct(private readonly NotificationService $notifications) {}

    public function submit(
        Evaluation $evaluation,
        User $actor,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Evaluation {
        $this->assertMayScore($evaluation, $actor);

        if ($evaluation->status !== Evaluation::STATUS_DRAFT) {
            throw new EvaluationException(
                'Only a draft evaluation can be submitted.',
                422,
                'EVALUATION_NOT_DRAFT',
                ['evaluation_id' => $evaluation->id, 'status' => $evaluation->status],
            );
        }

        $this->assertScorable($evaluation);

        if ($this->requiresModeration($evaluation)) {
            $evaluation->forceFill([
                'status' => Evaluation::STATUS_MODERATION,
                'submitted_at' => now(),
                'moderation_status' => 'pending',
            ])->save();

            EvaluationModerationCase::create([
                'type' => EvaluationModerationCase::TYPE_FLAGGED,
                'evaluation_id' => $evaluation->id,
                'status' => EvaluationModerationCase::STATUS_OPEN,
                'reason' => 'policy_requires_moderation',
            ]);

            $this->audit(
                $actor->id,
                'evaluation.submitted_for_moderation',
                'evaluation',
                $evaluation->id,
                null,
                ['project_id' => $evaluation->project_id],
                'project_evaluation',
                $requestId,
                $ipAddress,
                $userAgent,
            );

            return $evaluation->fresh();
        }

        $evaluation->forceFill([
            'status' => Evaluation::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ])->save();

        return $this->finalize($evaluation->fresh(), $actor, $requestId, $ipAddress, $userAgent);
    }

    public function finalize(
        Evaluation $evaluation,
        User $actor,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Evaluation {
        $this->assertMayFinalize($evaluation, $actor);

        if (! in_array($evaluation->status, [Evaluation::STATUS_SUBMITTED, Evaluation::STATUS_MODERATION], true)) {
            throw new EvaluationException(
                'Only a submitted evaluation can be finalized.',
                422,
                'EVALUATION_NOT_SUBMITTED',
                ['evaluation_id' => $evaluation->id, 'status' => $evaluation->status],
            );
        }

        if ($evaluation->conflict_declared && ! $evaluation->conflict_mitigation_approved) {
            throw new EvaluationException(
                'This evaluation cannot be finalized while a declared conflict of interest is unresolved.',
                422,
                'EVALUATION_CONFLICT_BLOCKED',
                ['evaluation_id' => $evaluation->id],
            );
        }

        $this->assertScorable($evaluation);

        $calculation = $this->calculate($evaluation);

        DB::transaction(function () use ($evaluation, $actor, $calculation): void {
            $evaluation->forceFill([
                'status' => Evaluation::STATUS_FINALIZED,
                'overall_score' => $calculation['overall_score'],
                'calculation_inputs' => $calculation['inputs'],
                'rubric_version' => (int) ($evaluation->rubric?->version ?? $evaluation->rubric_version),
                'finalized_at' => now(),
                'finalized_by' => $actor->id,
            ])->save();

            $this->createSkillEvidence($evaluation);

            EvaluationTask::query()
                ->where('project_id', $evaluation->project_id)
                ->where('participant_id', $evaluation->participant_id)
                ->update(['status' => EvaluationTask::STATUS_FINALIZED]);
        });

        $this->audit(
            $actor->id,
            'evaluation.finalized',
            'evaluation',
            $evaluation->id,
            ['status' => Evaluation::STATUS_SUBMITTED],
            [
                'status' => Evaluation::STATUS_FINALIZED,
                'overall_score' => $calculation['overall_score'],
                'rubric_id' => $evaluation->rubric_id,
                'rubric_version' => $evaluation->rubric_version,
            ],
            'project_evaluation',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        EvaluationFinalized::dispatch($evaluation->fresh());

        if ($evaluation->participant_id !== null) {
            $this->notifications->dispatch(
                userId: (int) $evaluation->participant_id,
                category: 'project_status',
                title: 'Your project evaluation is ready',
                body: 'The evaluation for '.($evaluation->project?->title ?? 'your project').' was finalized.',
                link: '/evaluations/'.$evaluation->id,
                eventKey: 'evaluation.finalized:'.$evaluation->id,
            );
        }

        return $evaluation->fresh();
    }

    public function calculate(Evaluation $evaluation): array
    {
        $evaluation->loadMissing(['dimensionScores.dimension', 'rubric.dimensions']);
        $rubric = $evaluation->rubric;

        if ($rubric === null) {
            throw new EvaluationException(
                'The evaluation is not linked to a rubric version.',
                422,
                'EVALUATION_RUBRIC_MISSING',
                ['evaluation_id' => $evaluation->id],
            );
        }

        $weights = [];
        $scores = [];
        $weighted = 0.0;
        $totalWeight = 0.0;

        foreach ($rubric->dimensions as $dimension) {
            $score = $evaluation->dimensionScores
                ->firstWhere('rubric_dimension_id', $dimension->id);

            if ($score === null) {
                continue;
            }

            $weight = (float) $dimension->weight;
            $value = (float) $score->score;

            $weights[(string) $dimension->name] = $weight;
            $scores[(string) $dimension->name] = $value;

            $weighted += $value * $weight;
            $totalWeight += $weight;
        }

        if ($totalWeight <= 0.0) {
            throw new EvaluationException(
                'The rubric version has no usable weights for this evaluation.',
                422,
                'EVALUATION_WEIGHTS_INVALID',
                ['evaluation_id' => $evaluation->id],
            );
        }

        $precision = (int) config('sprint5.evaluation.score_precision', 2);
        $overall = round($weighted / $totalWeight, $precision);

        return [
            'overall_score' => $overall,
            'inputs' => [
                'formula' => 'sum(score * weight) / sum(weight)',
                'rubric_id' => $rubric->id,
                'rubric_version' => (int) $rubric->version,
                'weights' => $weights,
                'scores' => $scores,
                'weighted_sum' => round($weighted, 6),
                'total_weight' => round($totalWeight, 6),
                'precision' => $precision,
                'computed_at' => now()->toIso8601String(),
            ],
        ];
    }

    private function createSkillEvidence(Evaluation $evaluation): void
    {
        if ($evaluation->participant_id === null) {
            return;
        }

        $profile = StudentProfile::query()->where('user_id', $evaluation->participant_id)->first();

        if ($profile === null) {
            return;
        }

        $evaluation->loadMissing(['dimensionScores.dimension.skills', 'rubric.dimensions']);

        foreach ($evaluation->dimensionScores as $dimensionScore) {
            $dimension = $dimensionScore->dimension;

            if (! $dimension instanceof RubricDimension) {
                continue;
            }

            $skillIds = $dimension->skillIds();

            if ($skillIds === []) {
                continue;
            }

            $level = $this->levelFromScore($dimension, (float) $dimensionScore->score);

            foreach ($skillIds as $skillId) {
                $exists = SkillEvidence::query()
                    ->where('student_profile_id', $profile->id)
                    ->where('skill_id', $skillId)
                    ->where('source_record_type', 'evaluation')
                    ->where('source_record_id', $evaluation->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                SkillEvidence::create([
                    'student_profile_id' => $profile->id,
                    'skill_id' => $skillId,
                    'source' => 'project_performance',
                    'evidence_type' => 'document',
                    'value' => $level,
                    'normalized_value' => $level,
                    'reference' => '/projects/'.$evaluation->project_id,
                    'description' => 'Project evaluation on '.$dimension->name.' (rubric v'.$evaluation->rubric_version.').',
                    'evidence_date' => now()->toDateString(),
                    'verification_status' => SkillEvidence::STATUS_VERIFIED,
                    'reviewer_id' => $evaluation->evaluator_id,
                    'source_record_type' => 'evaluation',
                    'source_record_id' => $evaluation->id,
                ]);
            }
        }

        SkillDataChanged::dispatch($profile, 'project_evaluation');
    }

    private function levelFromScore(RubricDimension $dimension, float $score): float
    {
        $min = $dimension->minimumScore();
        $max = $dimension->maximumScore();
        $span = $max - $min;

        if ($span <= 0) {
            return 1.0;
        }

        $ratio = max(0.0, min(1.0, ($score - $min) / $span));

        return round(1 + 4 * $ratio, 2);
    }

    private function assertScorable(Evaluation $evaluation): void
    {
        $evaluation->loadMissing(['dimensionScores.dimension', 'rubric.dimensions']);
        $rubric = $evaluation->rubric;

        if ($rubric === null || $rubric->dimensions->isEmpty()) {
            throw new EvaluationException(
                'The evaluation is not linked to a rubric version with dimensions.',
                422,
                'EVALUATION_RUBRIC_MISSING',
                ['evaluation_id' => $evaluation->id],
            );
        }

        $missing = [];
        $outOfRange = [];
        $unexplained = [];

        foreach ($rubric->dimensions as $dimension) {
            $score = $evaluation->dimensionScores->firstWhere('rubric_dimension_id', $dimension->id);

            if ($score === null) {
                $missing[] = $dimension->name;

                continue;
            }

            $value = (float) $score->score;

            if ($value < $dimension->minimumScore() || $value > $dimension->maximumScore()) {
                $outOfRange[$dimension->name] = [
                    'score' => $value,
                    'min' => $dimension->minimumScore(),
                    'max' => $dimension->maximumScore(),
                ];
            }

            if (trim((string) $score->comment) === '') {
                $unexplained[] = $dimension->name;
            }
        }

        if ($missing !== []) {
            throw new EvaluationException(
                'Every applicable rubric dimension must be scored before submission.',
                422,
                'EVALUATION_CRITERIA_MISSING',
                ['dimensions' => $missing],
            );
        }

        if ($outOfRange !== []) {
            throw new EvaluationException(
                'One or more scores are outside the range defined by the rubric version.',
                422,
                'EVALUATION_SCORE_OUT_OF_RANGE',
                ['dimensions' => $outOfRange],
            );
        }

        if ($unexplained !== []) {
            throw new EvaluationException(
                'Every scored criterion needs a written explanation.',
                422,
                'EVALUATION_EXPLANATION_REQUIRED',
                ['dimensions' => $unexplained],
            );
        }
    }

    private function requiresModeration(Evaluation $evaluation): bool
    {
        $mode = (string) config('sprint5.evaluation.moderation_mode', 'flagged_only');

        return match ($mode) {
            'all' => true,
            'none' => false,
            default => $evaluation->conflict_declared,
        };
    }

    private function assertMayScore(Evaluation $evaluation, User $actor): void
    {
        if ((int) $evaluation->evaluator_id === (int) $actor->id || $actor->hasRole('admin')) {
            return;
        }

        throw new EvaluationException(
            'Only the assigned evaluator can score this evaluation.',
            403,
            'EVALUATION_NOT_ASSIGNED',
            ['evaluation_id' => $evaluation->id],
        );
    }

    private function assertMayFinalize(Evaluation $evaluation, User $actor): void
    {
        if ((int) $evaluation->evaluator_id === (int) $actor->id || $actor->hasRole('admin')) {
            return;
        }

        throw new EvaluationException(
            'Only the assigned evaluator or an administrator can finalize this evaluation.',
            403,
            'EVALUATION_NOT_ASSIGNED',
            ['evaluation_id' => $evaluation->id],
        );
    }
}
