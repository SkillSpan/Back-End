<?php

namespace App\Services\Evaluation;

use App\Exceptions\EvaluationException;
use App\Models\Evaluation;
use App\Models\EvaluationCorrectionRequest;
use App\Models\EvaluationDispute;
use App\Models\EvaluationModerationCase;
use App\Models\EvaluationTask;
use App\Models\Project;
use App\Models\RubricDimension;
use App\Models\User;
use App\Services\Projects\ProjectWorkspaceAccess;
use App\Traits\AuditsActions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EvaluationService
{
    use AuditsActions;

    public function __construct(
        private readonly ProjectWorkspaceAccess $access,
        private readonly EvaluationFinalizationService $finalization,
    ) {}

    public function tasksFor(User $evaluator): Collection
    {
        return EvaluationTask::query()
            ->where('evaluator_id', $evaluator->id)
            ->whereIn('status', EvaluationTask::OPEN_STATUSES)
            ->with(['project', 'participant', 'submission', 'rubric'])
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();
    }

    public function taskPayload(EvaluationTask $task): array
    {
        $evaluation = $this->evaluationForTask($task);

        return [
            'id' => $task->id,
            'status' => $task->status,
            'routing_reason' => $task->routing_reason,
            'evaluator_type' => $task->evaluator_type,
            'assigned_at' => $task->assigned_at?->toIso8601String(),
            'due_at' => $task->due_at?->toIso8601String(),
            'project' => [
                'id' => $task->project?->id,
                'title' => $task->project?->title,
                'type' => $task->project?->type,
                'status' => $task->project?->status,
            ],
            'participant' => [
                'id' => $task->participant?->id,
                'name' => $task->participant?->name,
            ],
            'submission' => $task->submission === null ? null : [
                'id' => $task->submission->id,
                'version' => (int) $task->submission->version,
                'submitted_at' => $task->submission->submitted_at?->toIso8601String(),
                'first_pass_status' => $task->submission->first_pass_status,
                'reference' => $task->submission->link_url,
            ],
            'rubric' => [
                'id' => $task->rubric?->id,
                'title' => $task->rubric?->title,
                'version' => (int) ($task->rubric?->version ?? 0),
            ],
            'evaluation_id' => $evaluation?->id,
            'evaluation_status' => $evaluation?->status,
        ];
    }

    public function createDraft(
        User $evaluator,
        array $data,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Evaluation {
        $task = $this->resolveTask($data, $evaluator);

        $project = $task->project;

        if ($project === null) {
            throw new EvaluationException(
                'The evaluation task is not linked to a project.',
                422,
                'EVALUATION_TASK_INVALID',
                ['task_id' => $task->id],
            );
        }

        if ($project->rubric_id === null) {
            throw new EvaluationException(
                'This project has no published rubric version attached.',
                422,
                'EVALUATION_RUBRIC_MISSING',
                ['project_id' => $project->id],
            );
        }

        $existing = $this->evaluationForTask($task);

        if ($existing !== null && $existing->status !== Evaluation::STATUS_VOIDED && $existing->status !== Evaluation::STATUS_SUPERSEDED) {
            return $existing;
        }

        $evaluation = Evaluation::create([
            'project_id' => $project->id,
            'submission_id' => $task->submission_id,
            'participant_id' => $task->participant_id,
            'evaluator_id' => $evaluator->id,
            'evaluator_type' => $task->evaluator_type,
            'rubric_id' => $project->rubric_id,
            'rubric_version' => (int) ($task->rubric?->version ?? 1),
            'status' => Evaluation::STATUS_DRAFT,
        ]);

        $task->forceFill(['status' => EvaluationTask::STATUS_IN_PROGRESS])->save();

        $this->audit(
            $evaluator->id,
            'evaluation.draft_created',
            'evaluation',
            $evaluation->id,
            null,
            [
                'project_id' => $project->id,
                'participant_id' => $task->participant_id,
                'submission_id' => $task->submission_id,
                'rubric_id' => $project->rubric_id,
            ],
            'project_evaluation',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $evaluation->fresh();
    }

    public function updateDraft(
        Evaluation $evaluation,
        User $evaluator,
        array $data,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Evaluation {
        $this->assertOwnEvaluation($evaluation, $evaluator);

        if ($evaluation->status !== Evaluation::STATUS_DRAFT) {
            throw new EvaluationException(
                'A finalized evaluation cannot be edited. Raise a correction request instead.',
                422,
                'EVALUATION_IMMUTABLE',
                ['evaluation_id' => $evaluation->id, 'status' => $evaluation->status],
            );
        }

        DB::transaction(function () use ($evaluation, $data): void {
            foreach (['team_outcome', 'individual_contribution', 'improvement_guidance', 'confidential_notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $evaluation->{$field} = $data[$field];
                }
            }

            $evaluation->save();

            foreach ((array) ($data['dimension_scores'] ?? []) as $entry) {
                $dimension = RubricDimension::query()
                    ->where('rubric_id', $evaluation->rubric_id)
                    ->whereKey((int) ($entry['rubric_dimension_id'] ?? 0))
                    ->first();

                if ($dimension === null) {
                    throw new EvaluationException(
                        'One of the scored dimensions does not belong to the rubric version used by this evaluation.',
                        422,
                        'EVALUATION_DIMENSION_NOT_IN_RUBRIC',
                        ['rubric_dimension_id' => $entry['rubric_dimension_id'] ?? null],
                    );
                }

                $score = (float) ($entry['score'] ?? 0);

                if ($score < $dimension->minimumScore() || $score > $dimension->maximumScore()) {
                    throw new EvaluationException(
                        'The score is outside the range defined by the rubric version.',
                        422,
                        'EVALUATION_SCORE_OUT_OF_RANGE',
                        [
                            'dimension' => $dimension->name,
                            'score' => $score,
                            'min' => $dimension->minimumScore(),
                            'max' => $dimension->maximumScore(),
                        ],
                    );
                }

                $evaluation->dimensionScores()->updateOrCreate(
                    ['rubric_dimension_id' => $dimension->id],
                    [
                        'score' => $score,
                        'comment' => $entry['comment'] ?? null,
                    ],
                );
            }
        });

        $this->audit(
            $evaluator->id,
            'evaluation.draft_updated',
            'evaluation',
            $evaluation->id,
            null,
            [
                'scored_dimensions' => count((array) ($data['dimension_scores'] ?? [])),
                'status' => Evaluation::STATUS_DRAFT,
            ],
            'project_evaluation',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $evaluation->fresh(['dimensionScores.dimension']);
    }

    public function submit(
        Evaluation $evaluation,
        User $evaluator,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Evaluation {
        $this->assertOwnEvaluation($evaluation, $evaluator);

        return $this->finalization->submit($evaluation, $evaluator, $requestId, $ipAddress, $userAgent);
    }

    public function declareConflict(
        Evaluation $evaluation,
        User $evaluator,
        string $reason,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Evaluation {
        $this->assertOwnEvaluation($evaluation, $evaluator);

        if ($evaluation->isFinalized()) {
            throw new EvaluationException(
                'A conflict cannot be declared on a finalized evaluation.',
                422,
                'EVALUATION_IMMUTABLE',
                ['evaluation_id' => $evaluation->id, 'status' => $evaluation->status],
            );
        }

        $evaluation->forceFill([
            'conflict_declared' => true,
            'conflict_declared_at' => now(),
            'conflict_mitigation_approved' => false,
            'status' => Evaluation::STATUS_MODERATION,
            'moderation_status' => 'conflict_declared',
            'moderation_reason' => $reason,
        ])->save();

        EvaluationModerationCase::create([
            'type' => EvaluationModerationCase::TYPE_CONFLICT,
            'evaluation_id' => $evaluation->id,
            'status' => EvaluationModerationCase::STATUS_OPEN,
            'reason' => $reason,
        ]);

        EvaluationTask::query()
            ->where('project_id', $evaluation->project_id)
            ->where('participant_id', $evaluation->participant_id)
            ->update(['status' => EvaluationTask::STATUS_UNROUTABLE, 'routing_reason' => 'conflict_declared']);

        $this->audit(
            $evaluator->id,
            'evaluation.conflict_declared',
            'evaluation',
            $evaluation->id,
            null,
            ['reason' => $reason],
            'project_evaluation',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $evaluation->fresh();
    }

    public function projectEvaluations(Project $project, User $actor): array
    {
        $this->access->assertManager($project, $actor);

        $tasks = EvaluationTask::query()
            ->where('project_id', $project->id)
            ->with(['participant', 'submission', 'evaluator'])
            ->orderBy('id')
            ->get();

        return $tasks->map(function (EvaluationTask $task): array {
            $evaluation = $this->evaluationForTask($task);

            return [
                'task_id' => $task->id,
                'participant' => [
                    'id' => $task->participant?->id,
                    'name' => $task->participant?->name,
                ],
                'evaluator' => [
                    'id' => $task->evaluator?->id,
                    'name' => $task->evaluator?->name,
                    'type' => $task->evaluator_type,
                ],
                'submission' => $task->submission === null ? null : [
                    'id' => $task->submission->id,
                    'version' => (int) $task->submission->version,
                    'first_pass_status' => $task->submission->first_pass_status,
                ],
                'task_status' => $task->status,
                'evaluation' => $evaluation === null ? null : [
                    'id' => $evaluation->id,
                    'status' => $evaluation->status,
                    'overall_score' => $evaluation->overall_score,
                    'finalized_at' => $evaluation->finalized_at?->toIso8601String(),
                ],
            ];
        })->values()->all();
    }

    public function studentResults(User $student): Collection
    {
        return Evaluation::query()
            ->where('participant_id', $student->id)
            ->whereIn('status', Evaluation::STUDENT_VISIBLE_STATUSES)
            ->with(['project', 'rubric'])
            ->orderByDesc('finalized_at')
            ->orderByDesc('id')
            ->get();
    }

    public function resultPayload(Evaluation $evaluation): array
    {
        $evaluation->loadMissing(['project', 'rubric']);
        $period = (int) config('sprint5.evaluation.dispute_period_days', 14);
        $deadline = $evaluation->finalized_at?->copy()->addDays($period);

        $openDispute = $evaluation->disputes()
            ->whereIn('status', EvaluationDispute::OPEN_STATUSES)
            ->exists();

        $canDispute = $evaluation->status === Evaluation::STATUS_FINALIZED
            && ! $openDispute
            && ($deadline === null || now()->lessThanOrEqualTo($deadline));

        return [
            'evaluation_id' => $evaluation->id,
            'project' => [
                'id' => $evaluation->project?->id,
                'title' => $evaluation->project?->title,
                'type' => $evaluation->project?->type,
            ],
            'rubric' => [
                'id' => $evaluation->rubric_id,
                'version' => (int) $evaluation->rubric_version,
                'title' => $evaluation->rubric?->title,
            ],
            'status' => $evaluation->status,
            'overall_score' => $evaluation->overall_score,
            'team_outcome' => $evaluation->team_outcome,
            'individual_contribution' => $evaluation->individual_contribution,
            'improvement_guidance' => $evaluation->improvement_guidance,
            'finalized_at' => $evaluation->finalized_at?->toIso8601String(),
            'dispute_deadline' => $deadline?->toIso8601String(),
            'has_open_dispute' => $openDispute,
            'can_dispute' => $canDispute,
        ];
    }

    public function requestCorrection(
        Evaluation $evaluation,
        User $actor,
        string $reason,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): EvaluationCorrectionRequest {
        if (! $evaluation->isFinalized()) {
            throw new EvaluationException(
                'A correction can only be requested for a finalized evaluation.',
                422,
                'EVALUATION_NOT_FINALIZED',
                ['evaluation_id' => $evaluation->id, 'status' => $evaluation->status],
            );
        }

        $project = $evaluation->project;

        $allowed = $actor->hasRole('admin')
            || (int) $evaluation->evaluator_id === (int) $actor->id
            || ($project !== null && $this->access->mayManage($project, $actor));

        if (! $allowed) {
            throw new EvaluationException(
                'You are not authorized to request a correction for this evaluation.',
                403,
                'EVALUATION_CORRECTION_FORBIDDEN',
                ['evaluation_id' => $evaluation->id],
            );
        }

        $open = EvaluationCorrectionRequest::query()
            ->where('evaluation_id', $evaluation->id)
            ->where('status', EvaluationCorrectionRequest::STATUS_PENDING)
            ->exists();

        if ($open) {
            throw new EvaluationException(
                'A correction request for this evaluation is already pending.',
                422,
                'EVALUATION_CORRECTION_ALREADY_PENDING',
                ['evaluation_id' => $evaluation->id],
            );
        }

        $request = EvaluationCorrectionRequest::create([
            'evaluation_id' => $evaluation->id,
            'requested_by' => $actor->id,
            'reason' => $reason,
            'status' => EvaluationCorrectionRequest::STATUS_PENDING,
        ]);

        EvaluationModerationCase::create([
            'type' => EvaluationModerationCase::TYPE_CORRECTION,
            'evaluation_id' => $evaluation->id,
            'correction_request_id' => $request->id,
            'status' => EvaluationModerationCase::STATUS_OPEN,
            'reason' => $reason,
        ]);

        $this->audit(
            $actor->id,
            'evaluation.correction_requested',
            'evaluation',
            $evaluation->id,
            null,
            ['correction_request_id' => $request->id, 'reason' => $reason],
            'project_evaluation',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $request->fresh();
    }

    public function evaluationForTask(EvaluationTask $task): ?Evaluation
    {
        return Evaluation::query()
            ->where('project_id', $task->project_id)
            ->where('participant_id', $task->participant_id)
            ->whereIn('status', [
                Evaluation::STATUS_DRAFT,
                Evaluation::STATUS_SUBMITTED,
                Evaluation::STATUS_MODERATION,
                Evaluation::STATUS_FINALIZED,
                Evaluation::STATUS_DISPUTED,
            ])
            ->latest('id')
            ->first();
    }

    public function payload(Evaluation $evaluation, bool $includeConfidential = false): array
    {
        $evaluation->loadMissing(['dimensionScores.dimension', 'rubric.dimensions']);

        $payload = [
            'id' => $evaluation->id,
            'project_id' => $evaluation->project_id,
            'participant_id' => $evaluation->participant_id,
            'evaluator' => [
                'id' => $evaluation->evaluator_id,
                'type' => $evaluation->evaluator_type,
            ],
            'rubric' => [
                'id' => $evaluation->rubric_id,
                'version' => (int) $evaluation->rubric_version,
                'title' => $evaluation->rubric?->title,
            ],
            'status' => $evaluation->status,
            'overall_score' => $evaluation->overall_score,
            'team_outcome' => $evaluation->team_outcome,
            'individual_contribution' => $evaluation->individual_contribution,
            'improvement_guidance' => $evaluation->improvement_guidance,
            'conflict_declared' => (bool) $evaluation->conflict_declared,
            'submitted_at' => $evaluation->submitted_at?->toIso8601String(),
            'finalized_at' => $evaluation->finalized_at?->toIso8601String(),
            'criteria' => $evaluation->dimensionScores->map(fn ($score) => [
                'rubric_dimension_id' => $score->rubric_dimension_id,
                'name' => $score->dimension?->name,
                'weight' => (float) ($score->dimension?->weight ?? 0),
                'score' => (float) $score->score,
                'explanation' => $score->comment,
            ])->values()->all(),
        ];

        if ($includeConfidential) {
            $payload['confidential_notes'] = $evaluation->confidential_notes;
        }

        return $payload;
    }

    private function resolveTask(array $data, User $evaluator): EvaluationTask
    {
        $taskId = isset($data['task_id']) ? (int) $data['task_id'] : null;

        $task = $taskId !== null
            ? EvaluationTask::query()->with(['project', 'rubric', 'submission'])->find($taskId)
            : EvaluationTask::query()
                ->with(['project', 'rubric', 'submission'])
                ->where('project_id', (int) ($data['project_id'] ?? 0))
                ->where('participant_id', (int) ($data['participant_id'] ?? 0))
                ->first();

        if ($task === null) {
            throw new EvaluationException(
                'The evaluation task does not exist.',
                404,
                'EVALUATION_TASK_NOT_FOUND',
                ['task_id' => $taskId],
            );
        }

        if ((int) $task->evaluator_id !== (int) $evaluator->id) {
            throw new EvaluationException(
                'You are not the assigned evaluator for this task.',
                403,
                'EVALUATION_NOT_ASSIGNED',
                ['task_id' => $task->id],
            );
        }

        if ($task->submission_id === null && $task->status === EvaluationTask::STATUS_UNROUTABLE) {
            throw new EvaluationException(
                'This evaluation task has not been routed to an evaluator yet.',
                422,
                'EVALUATION_TASK_UNROUTABLE',
                ['task_id' => $task->id],
            );
        }

        return $task;
    }

    private function assertOwnEvaluation(Evaluation $evaluation, User $evaluator): void
    {
        if ((int) $evaluation->evaluator_id === (int) $evaluator->id) {
            return;
        }

        throw new EvaluationException(
            'Only the assigned evaluator can score this evaluation.',
            403,
            'EVALUATION_NOT_ASSIGNED',
            ['evaluation_id' => $evaluation->id],
        );
    }
}
