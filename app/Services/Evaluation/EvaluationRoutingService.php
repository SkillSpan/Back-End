<?php

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationModerationCase;
use App\Models\EvaluationTask;
use App\Models\Project;
use App\Models\ProjectDelegate;
use App\Models\Submission;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Projects\ProjectWorkspaceAccess;
use App\Traits\AuditsActions;

class EvaluationRoutingService
{
    use AuditsActions;

    public function __construct(
        private readonly ProjectWorkspaceAccess $access,
        private readonly NotificationService $notifications,
    ) {}

    public function routeProject(Project $project): array
    {
        $tasks = [];

        foreach ($this->access->memberIds($project) as $participantId) {
            $submission = $this->submissionForParticipant($project, $participantId);

            if ($submission === null) {
                continue;
            }

            $tasks[] = $this->route($project, $participantId, $submission);
        }

        return $tasks;
    }

    public function route(Project $project, int $participantId, Submission $submission): EvaluationTask
    {
        $existing = EvaluationTask::query()
            ->where('submission_id', $submission->id)
            ->where('participant_id', $participantId)
            ->first();

        if ($existing !== null && $existing->status !== EvaluationTask::STATUS_UNROUTABLE && $existing->evaluator_id !== null) {
            return $existing;
        }

        [$evaluatorId, $evaluatorType, $reason] = $this->resolveEvaluator($project);

        if ($evaluatorId === null || $project->rubric_id === null) {
            $task = EvaluationTask::updateOrCreate(
                ['submission_id' => $submission->id, 'participant_id' => $participantId],
                [
                    'project_id' => $project->id,
                    'evaluator_id' => null,
                    'evaluator_type' => $evaluatorType,
                    'rubric_id' => $project->rubric_id,
                    'status' => EvaluationTask::STATUS_UNROUTABLE,
                    'routing_reason' => $project->rubric_id === null ? 'rubric_missing' : $reason,
                ],
            );

            $this->openModerationCase($project, $task, $project->rubric_id === null ? 'rubric_missing' : $reason);
            $this->notifyOwnerAndAdmins($project, $task);

            return $task;
        }

        $task = EvaluationTask::updateOrCreate(
            ['submission_id' => $submission->id, 'participant_id' => $participantId],
            [
                'project_id' => $project->id,
                'evaluator_id' => $evaluatorId,
                'evaluator_type' => $evaluatorType,
                'rubric_id' => $project->rubric_id,
                'status' => EvaluationTask::STATUS_ASSIGNED,
                'routing_reason' => $reason,
                'assigned_at' => now(),
                'due_at' => now()->addDays((int) config('sprint5.evaluation.default_due_days', 7)),
            ],
        );

        $this->audit(
            $evaluatorId,
            'evaluation.task_routed',
            'evaluation_task',
            $task->id,
            null,
            [
                'project_id' => $project->id,
                'participant_id' => $participantId,
                'submission_id' => $submission->id,
                'evaluator_type' => $evaluatorType,
                'reason' => $reason,
            ],
            'project_evaluation',
        );

        $this->notifications->dispatch(
            userId: $evaluatorId,
            category: 'project_status',
            title: 'New evaluation task',
            body: 'A submission is ready for evaluation on '.$project->title.'.',
            link: '/evaluations/tasks/'.$task->id,
            eventKey: 'evaluation.task:'.$task->id,
        );

        return $task;
    }

    public function submissionForParticipant(Project $project, int $participantId): ?Submission
    {
        $final = Submission::query()
            ->where('project_id', $project->id)
            ->where('contributor_id', $participantId)
            ->whereNull('project_milestone_id')
            ->where('is_current', true)
            ->latest('submitted_at')
            ->first();

        if ($final !== null) {
            return $final;
        }

        return Submission::query()
            ->where('project_id', $project->id)
            ->where('contributor_id', $participantId)
            ->where('is_current', true)
            ->latest('submitted_at')
            ->first();
    }

    private function resolveEvaluator(Project $project): array
    {
        if ($project->isSimulation()) {
            $owner = $project->owner;

            if ($owner !== null && ($owner->hasRole('mentor') || $owner->hasRole('reviewer')) && ! $this->hasConflict($project, $owner->id)) {
                return [(int) $owner->id, Evaluation::EVALUATOR_TYPE_MENTOR, 'simulation_project_mentor'];
            }

            return [null, Evaluation::EVALUATOR_TYPE_MENTOR, 'no_eligible_mentor'];
        }

        $owner = $project->owner;

        if ($owner !== null && $owner->hasRole('company_admin') && ! $this->hasConflict($project, $owner->id)) {
            return [(int) $owner->id, Evaluation::EVALUATOR_TYPE_COMPANY, 'company_sponsored_owner'];
        }

        $delegate = ProjectDelegate::query()
            ->where('project_id', $project->id)
            ->whereNull('revoked_at')
            ->where('user_id', '!=', $owner?->id ?? 0)
            ->first();

        if ($delegate !== null && ! $this->hasConflict($project, (int) $delegate->user_id)) {
            return [(int) $delegate->user_id, Evaluation::EVALUATOR_TYPE_DELEGATE, 'approved_delegate'];
        }

        return [null, Evaluation::EVALUATOR_TYPE_COMPANY, 'no_eligible_company_evaluator'];
    }

    private function hasConflict(Project $project, int $userId): bool
    {
        return Evaluation::query()
            ->where('project_id', $project->id)
            ->where('evaluator_id', $userId)
            ->where('conflict_declared', true)
            ->exists();
    }

    private function openModerationCase(Project $project, EvaluationTask $task, string $reason): void
    {
        $exists = EvaluationModerationCase::query()
            ->where('type', EvaluationModerationCase::TYPE_FLAGGED)
            ->where('status', EvaluationModerationCase::STATUS_OPEN)
            ->where('source_type', 'evaluation_task')
            ->where('source_id', $task->id)
            ->exists();

        if ($exists) {
            return;
        }

        EvaluationModerationCase::create([
            'type' => EvaluationModerationCase::TYPE_FLAGGED,
            'status' => EvaluationModerationCase::STATUS_OPEN,
            'reason' => $reason,
            'source_type' => 'evaluation_task',
            'source_id' => $task->id,
        ]);
    }

    private function notifyOwnerAndAdmins(Project $project, EvaluationTask $task): void
    {
        $recipients = User::query()
            ->whereHas('roles', fn ($q) => $q->where('slug', 'admin'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($project->owner_id !== null) {
            $recipients[] = (int) $project->owner_id;
        }

        foreach (array_unique($recipients) as $userId) {
            $this->notifications->dispatch(
                userId: $userId,
                category: 'project_status',
                title: 'Evaluation needs a moderator',
                body: 'No eligible evaluator was found for a submission on '.$project->title.'.',
                link: '/admin/moderation',
                eventKey: 'evaluation.unroutable:'.$task->id.':'.$userId,
            );
        }
    }
}
