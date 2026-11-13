<?php

namespace App\Services\Evaluation;

use App\Exceptions\EvaluationException;
use App\Models\Evaluation;
use App\Models\EvaluationDispute;
use App\Models\EvaluationException as EvaluationExceptionRecord;
use App\Models\EvaluationModerationCase;
use App\Models\RubricDimension;
use App\Models\User;
use App\Services\NotificationService;
use App\Traits\AuditsActions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DisputeService
{
    use AuditsActions;

    public function __construct(private readonly NotificationService $notifications) {}

    public function open(
        Evaluation $evaluation,
        User $student,
        array $data,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): EvaluationDispute {
        if ((int) $evaluation->participant_id !== (int) $student->id) {
            throw new EvaluationException(
                'Only the evaluated participant can dispute this evaluation.',
                403,
                'DISPUTE_FORBIDDEN',
                ['evaluation_id' => $evaluation->id],
            );
        }

        if ($evaluation->status !== Evaluation::STATUS_FINALIZED) {
            throw new EvaluationException(
                'Only a finalized evaluation can be disputed.',
                422,
                'EVALUATION_NOT_FINALIZED',
                ['evaluation_id' => $evaluation->id, 'status' => $evaluation->status],
            );
        }

        $openDispute = EvaluationDispute::query()
            ->where('evaluation_id', $evaluation->id)
            ->whereIn('status', EvaluationDispute::OPEN_STATUSES)
            ->exists();

        if ($openDispute) {
            throw new EvaluationException(
                'A dispute for this evaluation is already open.',
                422,
                'DISPUTE_ALREADY_OPEN',
                ['evaluation_id' => $evaluation->id],
            );
        }

        $this->assertWithinWindow($evaluation, $data['exception_id'] ?? null);

        $criteria = $this->resolveCriteria($evaluation, (array) ($data['disputed_criteria'] ?? []));

        $dispute = DB::transaction(function () use ($evaluation, $student, $data, $criteria): EvaluationDispute {
            $dispute = EvaluationDispute::create([
                'evaluation_id' => $evaluation->id,
                'submitted_by' => $student->id,
                'grounds' => $data['grounds'],
                'disputed_criteria' => $criteria,
                'rubric_version' => (int) $evaluation->rubric_version,
                'evidence' => $data['evidence'] ?? null,
                'status' => EvaluationDispute::STATUS_PENDING,
                'exception_id' => $data['exception_id'] ?? null,
            ]);

            $evaluation->forceFill([
                'status' => Evaluation::STATUS_DISPUTED,
                'moderation_status' => 'disputed',
                'moderation_reason' => 'student_dispute',
            ])->save();

            EvaluationModerationCase::create([
                'type' => EvaluationModerationCase::TYPE_DISPUTE,
                'evaluation_id' => $evaluation->id,
                'dispute_id' => $dispute->id,
                'status' => EvaluationModerationCase::STATUS_OPEN,
                'reason' => 'student_dispute',
            ]);

            return $dispute;
        });

        $this->audit(
            $student->id,
            'evaluation.disputed',
            'evaluation',
            $evaluation->id,
            null,
            [
                'dispute_id' => $dispute->id,
                'disputed_criteria' => $criteria,
                'rubric_version' => $dispute->rubric_version,
            ],
            'project_evaluation',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        if ($evaluation->evaluator_id !== null) {
            $this->notifications->dispatch(
                userId: (int) $evaluation->evaluator_id,
                category: 'project_status',
                title: 'An evaluation you wrote was disputed',
                body: 'The participant disputed the evaluation for '.($evaluation->project?->title ?? 'a project').'.',
                link: '/evaluations/'.$evaluation->id,
                eventKey: 'evaluation.disputed:'.$dispute->id,
            );
        }

        return $dispute->fresh();
    }

    public function listForEvaluation(Evaluation $evaluation, User $viewer): Collection
    {
        $allowed = $viewer->hasRole('admin')
            || (int) $evaluation->participant_id === (int) $viewer->id
            || (int) $evaluation->evaluator_id === (int) $viewer->id;

        if (! $allowed) {
            throw new EvaluationException(
                'You are not allowed to view the disputes for this evaluation.',
                403,
                'DISPUTE_FORBIDDEN',
                ['evaluation_id' => $evaluation->id],
            );
        }

        return EvaluationDispute::query()
            ->where('evaluation_id', $evaluation->id)
            ->orderByDesc('id')
            ->get();
    }

    public function listForStudent(User $student): Collection
    {
        return EvaluationDispute::query()
            ->where('submitted_by', $student->id)
            ->with(['evaluation.project'])
            ->orderByDesc('id')
            ->get();
    }

    public function payload(EvaluationDispute $dispute): array
    {
        return [
            'id' => $dispute->id,
            'evaluation_id' => $dispute->evaluation_id,
            'status' => $dispute->status,
            'grounds' => $dispute->grounds,
            'disputed_criteria' => $dispute->disputed_criteria ?? [],
            'rubric_version' => (int) $dispute->rubric_version,
            'evidence' => $dispute->evidence,
            'classification' => $dispute->classification,
            'corrective_action' => $dispute->corrective_action,
            'outcome_reason' => $dispute->outcome_reason,
            'resolution_notes' => $dispute->resolution_notes,
            'resolved_at' => $dispute->resolved_at?->toIso8601String(),
            'created_at' => $dispute->created_at?->toIso8601String(),
        ];
    }

    private function assertWithinWindow(Evaluation $evaluation, mixed $exceptionId): void
    {
        $period = (int) config('sprint5.evaluation.dispute_period_days', 14);
        $deadline = $evaluation->finalized_at?->copy()->addDays($period);

        if ($deadline === null || now()->lessThanOrEqualTo($deadline)) {
            return;
        }

        if ($exceptionId !== null) {
            $exception = EvaluationExceptionRecord::query()
                ->whereKey((int) $exceptionId)
                ->where('type', EvaluationExceptionRecord::TYPE_LATE_DISPUTE)
                ->first();

            if ($exception !== null) {
                return;
            }
        }

        throw new EvaluationException(
            'The dispute window for this evaluation has closed.',
            422,
            'DISPUTE_WINDOW_CLOSED',
            [
                'evaluation_id' => $evaluation->id,
                'deadline' => $deadline->toIso8601String(),
            ],
        );
    }

    private function resolveCriteria(Evaluation $evaluation, array $criteria): array
    {
        if ($criteria === []) {
            return [];
        }

        $allowed = RubricDimension::query()
            ->where('rubric_id', $evaluation->rubric_id)
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->all();

        $invalid = array_values(array_diff($criteria, $allowed));

        if ($invalid !== []) {
            throw new EvaluationException(
                'One or more disputed criteria do not belong to the rubric version used by this evaluation.',
                422,
                'DISPUTE_CRITERIA_INVALID',
                ['criteria' => $invalid],
            );
        }

        return array_values(array_unique($criteria));
    }
}
