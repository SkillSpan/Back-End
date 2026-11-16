<?php

namespace App\Services\Evaluation;

use App\Exceptions\EvaluationException;
use App\Models\Evaluation;
use App\Models\EvaluationDispute;
use App\Models\EvaluationException as EvaluationExceptionRecord;
use App\Models\EvaluationModerationCase;
use App\Models\ProfessionalRecordItem;
use App\Models\SkillEvidence;
use App\Models\User;
use App\Services\NotificationService;
use App\Traits\AuditsActions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ModerationService
{
    use AuditsActions;

    public function __construct(private readonly NotificationService $notifications) {}

    public function queue(string $status = 'open'): Collection
    {
        return EvaluationModerationCase::query()
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->with(['evaluation.project', 'evaluation.participant', 'evaluation.evaluator', 'dispute'])
            ->orderBy('id')
            ->get();
    }

    public function decide(
        EvaluationModerationCase $case,
        User $moderator,
        array $data,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): EvaluationModerationCase {
        if ($case->status !== EvaluationModerationCase::STATUS_OPEN) {
            throw new EvaluationException(
                'This moderation case has already been resolved.',
                422,
                'MODERATION_CASE_CLOSED',
                ['case_id' => $case->id, 'status' => $case->status],
            );
        }

        $decision = (string) $data['decision'];

        if (! in_array($decision, EvaluationModerationCase::DECISIONS, true)) {
            throw new EvaluationException(
                'That moderation decision is not supported.',
                422,
                'MODERATION_DECISION_INVALID',
                ['decision' => $decision, 'allowed' => EvaluationModerationCase::DECISIONS],
            );
        }

        $evaluation = $case->evaluation;

        if ($evaluation === null) {
            throw new EvaluationException(
                'This moderation case is not linked to an evaluation.',
                422,
                'MODERATION_CASE_INVALID',
                ['case_id' => $case->id],
            );
        }

        if ((int) $evaluation->evaluator_id === (int) $moderator->id) {
            throw new EvaluationException(
                'A moderator cannot decide on an evaluation they wrote.',
                403,
                'MODERATION_SELF_FORBIDDEN',
                ['evaluation_id' => $evaluation->id],
            );
        }

        $reason = (string) $data['reason'];

        DB::transaction(function () use ($case, $evaluation, $moderator, $decision, $data, $reason): void {
            $case->forceFill([
                'status' => EvaluationModerationCase::STATUS_RESOLVED,
                'decision' => $decision,
                'reason' => $reason,
                'decided_by' => $moderator->id,
                'decided_at' => now(),
            ])->save();

            match ($decision) {
                EvaluationModerationCase::DECISION_PRESERVE => $this->preserve($evaluation, $data, $reason),
                EvaluationModerationCase::DECISION_REPLACE => $this->replace($evaluation, $data, $reason),
                EvaluationModerationCase::DECISION_VOID => $this->void($evaluation, $data, $reason),
            };

            $this->resolveDispute($case, $data, $reason, $decision);
        });

        $this->audit(
            $moderator->id,
            'moderation.decided',
            'evaluation',
            $evaluation->id,
            ['case_status' => EvaluationModerationCase::STATUS_OPEN],
            [
                'case_id' => $case->id,
                'decision' => $decision,
                'reason' => $reason,
            ],
            'project_evaluation',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        foreach (array_filter([$evaluation->participant_id, $evaluation->evaluator_id]) as $userId) {
            $this->notifications->dispatch(
                userId: (int) $userId,
                category: 'project_status',
                title: 'A moderation decision was recorded',
                body: 'The evaluation for '.($evaluation->project?->title ?? 'a project').' was reviewed.',
                link: '/evaluations/'.$evaluation->id,
                eventKey: 'moderation.decided:'.$case->id.':'.$userId,
            );
        }

        return $case->fresh();
    }

    public function recordException(
        User $actor,
        array $data,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): EvaluationExceptionRecord {
        $type = (string) $data['type'];

        if (! in_array($type, EvaluationExceptionRecord::TYPES, true)) {
            throw new EvaluationException(
                'That exception type is not supported.',
                422,
                'EXCEPTION_TYPE_INVALID',
                ['type' => $type, 'allowed' => EvaluationExceptionRecord::TYPES],
            );
        }

        $exception = EvaluationExceptionRecord::create([
            'type' => $type,
            'subject_type' => $data['subject_type'],
            'subject_id' => (int) $data['subject_id'],
            'actor_id' => isset($data['actor_id']) ? (int) $data['actor_id'] : null,
            'reason' => $data['reason'],
        ]);

        $this->audit(
            $actor->id,
            'evaluation.exception_recorded',
            'evaluation',
            (int) $data['subject_id'],
            null,
            ['exception_id' => $exception->id, 'type' => $type],
            'project_evaluation',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $exception->fresh();
    }

    public function exceptions(string $type = 'all'): Collection
    {
        return EvaluationExceptionRecord::query()
            ->when($type !== 'all', fn ($query) => $query->where('type', $type))
            ->orderByDesc('id')
            ->get();
    }

    public function casePayload(EvaluationModerationCase $case): array
    {
        return [
            'id' => $case->id,
            'type' => $case->type,
            'status' => $case->status,
            'decision' => $case->decision,
            'reason' => $case->reason,
            'decided_at' => $case->decided_at?->toIso8601String(),
            'decided_by' => $case->decided_by,
            'evaluation' => $case->evaluation === null ? null : [
                'id' => $case->evaluation->id,
                'status' => $case->evaluation->status,
                'overall_score' => $case->evaluation->overall_score,
                'participant' => [
                    'id' => $case->evaluation->participant?->id,
                    'name' => $case->evaluation->participant?->name,
                ],
                'evaluator' => [
                    'id' => $case->evaluation->evaluator?->id,
                    'name' => $case->evaluation->evaluator?->name,
                ],
                'project' => [
                    'id' => $case->evaluation->project?->id,
                    'title' => $case->evaluation->project?->title,
                ],
            ],
            'dispute_id' => $case->dispute_id,
            'correction_request_id' => $case->correction_request_id,
            'source_type' => $case->source_type,
            'source_id' => $case->source_id,
            'created_at' => $case->created_at?->toIso8601String(),
        ];
    }

    public function exceptionPayload(EvaluationExceptionRecord $exception): array
    {
        return [
            'id' => $exception->id,
            'type' => $exception->type,
            'subject_type' => $exception->subject_type,
            'subject_id' => $exception->subject_id,
            'actor_id' => $exception->actor_id,
            'reason' => $exception->reason,
            'created_at' => $exception->created_at?->toIso8601String(),
        ];
    }

    private function preserve(Evaluation $evaluation, array $data, string $reason): void
    {
        $evaluation->forceFill([
            'status' => Evaluation::STATUS_FINALIZED,
            'moderation_status' => 'preserved',
            'moderation_reason' => $reason,
        ])->save();

        ProfessionalRecordItem::query()
            ->where('idempotency_key', 'evaluation:'.$evaluation->id)
            ->update(['status' => ProfessionalRecordItem::STATUS_ACTIVE]);
    }

    private function replace(Evaluation $evaluation, array $data, string $reason): void
    {
        $replacementEvaluator = isset($data['replacement_evaluator_id'])
            ? (int) $data['replacement_evaluator_id']
            : (int) $evaluation->evaluator_id;

        $replacement = Evaluation::create([
            'project_id' => $evaluation->project_id,
            'submission_id' => $evaluation->submission_id,
            'participant_id' => $evaluation->participant_id,
            'evaluator_id' => $replacementEvaluator,
            'evaluator_type' => $evaluation->evaluator_type,
            'rubric_id' => $evaluation->rubric_id,
            'rubric_version' => $evaluation->rubric_version,
            'status' => Evaluation::STATUS_DRAFT,
            'supersedes_evaluation_id' => $evaluation->id,
        ]);

        $evaluation->forceFill([
            'status' => Evaluation::STATUS_SUPERSEDED,
            'superseded_by_evaluation_id' => $replacement->id,
            'moderation_status' => 'replaced',
            'moderation_reason' => $reason,
        ])->save();

        $this->purgeEvidence($evaluation);
    }

    private function void(Evaluation $evaluation, array $data, string $reason): void
    {
        $evaluation->forceFill([
            'status' => Evaluation::STATUS_VOIDED,
            'moderation_status' => 'voided',
            'moderation_reason' => $reason,
        ])->save();

        $this->purgeEvidence($evaluation);
    }

    private function purgeEvidence(Evaluation $evaluation): void
    {
        SkillEvidence::query()
            ->where('source_record_type', 'evaluation')
            ->where('source_record_id', $evaluation->id)
            ->delete();

        $recordStatus = $evaluation->status === Evaluation::STATUS_SUPERSEDED
            ? ProfessionalRecordItem::STATUS_SUPERSEDED
            : ProfessionalRecordItem::STATUS_VOIDED;

        ProfessionalRecordItem::query()
            ->where('idempotency_key', 'evaluation:'.$evaluation->id)
            ->update(['status' => $recordStatus]);
    }

    private function resolveDispute(EvaluationModerationCase $case, array $data, string $reason, string $decision): void
    {
        if ($case->dispute_id === null) {
            return;
        }

        $dispute = EvaluationDispute::query()->find($case->dispute_id);

        if ($dispute === null) {
            return;
        }

        $defaultAction = match ($decision) {
            EvaluationModerationCase::DECISION_REPLACE => 'supersede_decision',
            EvaluationModerationCase::DECISION_VOID => 'repair_data',
            default => 'reject_with_reason',
        };

        $dispute->forceFill([
            'status' => $decision === EvaluationModerationCase::DECISION_PRESERVE
                ? EvaluationDispute::STATUS_REJECTED
                : EvaluationDispute::STATUS_RESOLVED,
            'classification' => $data['classification'] ?? 'misunderstanding',
            'corrective_action' => $data['corrective_action'] ?? $defaultAction,
            'outcome_reason' => $data['outcome_reason'] ?? $reason,
            'resolution_notes' => $data['resolution_notes'] ?? $reason,
            'resolved_by' => $case->decided_by,
            'resolved_at' => now(),
        ])->save();
    }
}
