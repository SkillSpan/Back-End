<?php

namespace App\Services\Projects;

use App\Exceptions\ProjectException;
use App\Models\MilestoneExtensionRequest;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\User;
use App\Services\NotificationService;
use App\Traits\AuditsActions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExtensionRequestService
{
    use AuditsActions;

    public function __construct(
        private readonly ProjectWorkspaceAccess $access,
        private readonly NotificationService $notifications,
    ) {}

    public function request(
        Project $project,
        ProjectMilestone $milestone,
        User $participant,
        array $data,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): MilestoneExtensionRequest {
        $this->access->assertMember($project, $participant);

        if ((int) $milestone->project_id !== (int) $project->id) {
            throw new ProjectException(
                'This milestone does not belong to the selected project.',
                404,
                'MILESTONE_NOT_FOUND',
                ['milestone_id' => $milestone->id, 'project_id' => $project->id],
            );
        }

        if ($milestone->status === ProjectMilestone::STATUS_COMPLETED) {
            throw new ProjectException(
                'This milestone is already completed, so an extension cannot be requested.',
                422,
                'EXTENSION_MILESTONE_COMPLETED',
                ['milestone_id' => $milestone->id],
            );
        }

        $reason = trim((string) ($data['reason'] ?? ''));

        if ($reason === '') {
            throw new ProjectException(
                'A reason is required when requesting an extension.',
                422,
                'EXTENSION_REASON_REQUIRED',
                ['milestone_id' => $milestone->id],
            );
        }

        $cutoff = $this->cutoffFor($milestone);

        if ($cutoff !== null && Carbon::now()->greaterThan($cutoff)) {
            throw new ProjectException(
                'The extension request window for this milestone has closed.',
                422,
                'EXTENSION_CUTOFF_PASSED',
                [
                    'milestone_id' => $milestone->id,
                    'cutoff' => $cutoff->toIso8601String(),
                    'effective_deadline' => $milestone->effectiveDeadline()?->toDateString(),
                ],
            );
        }

        $requestedDate = Carbon::parse((string) $data['new_due_date'])->startOfDay();
        $currentDeadline = $milestone->effectiveDeadline();

        if ($currentDeadline !== null && $requestedDate->lessThanOrEqualTo($currentDeadline->copy()->startOfDay())) {
            throw new ProjectException(
                'The requested deadline must be later than the current effective deadline.',
                422,
                'EXTENSION_DATE_NOT_LATER',
                [
                    'milestone_id' => $milestone->id,
                    'current_deadline' => $currentDeadline->toDateString(),
                    'requested_deadline' => $requestedDate->toDateString(),
                ],
            );
        }

        $pending = $milestone->extensionRequests()
            ->where('requested_by', $participant->id)
            ->where('status', MilestoneExtensionRequest::STATUS_PENDING)
            ->exists();

        if ($pending) {
            throw new ProjectException(
                'You already have a pending extension request for this milestone.',
                422,
                'EXTENSION_ALREADY_PENDING',
                ['milestone_id' => $milestone->id],
            );
        }

        $extension = DB::transaction(fn () => $milestone->extensionRequests()->create([
            'requested_by' => $participant->id,
            'reason' => $reason,
            'new_due_date' => $requestedDate->toDateString(),
            'status' => MilestoneExtensionRequest::STATUS_PENDING,
        ]));

        $this->audit(
            $participant->id,
            'extension.requested',
            'milestone_extension_request',
            $extension->id,
            null,
            [
                'milestone_id' => $milestone->id,
                'project_id' => $project->id,
                'requested_deadline' => $requestedDate->toDateString(),
                'reason' => $reason,
            ],
            'project_milestone',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        if ($project->owner_id !== null) {
            $this->notifications->dispatch(
                userId: (int) $project->owner_id,
                category: 'project_status',
                title: 'Extension request received',
                body: $participant->name.' requested an extension on '.$milestone->title.'.',
                link: '/projects/'.$project->id,
                eventKey: 'extension.requested:'.$extension->id,
            );
        }

        return $extension->fresh();
    }

    public function decide(
        Project $project,
        MilestoneExtensionRequest $extension,
        User $actor,
        string $decision,
        ?string $newDeadline,
        ?string $reason,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): MilestoneExtensionRequest {
        $this->access->assertManager($project, $actor);

        $milestone = $extension->milestone;

        if (! $milestone || (int) $milestone->project_id !== (int) $project->id) {
            throw new ProjectException(
                'This extension request does not belong to the selected project.',
                404,
                'EXTENSION_NOT_FOUND',
                ['extension_request_id' => $extension->id, 'project_id' => $project->id],
            );
        }

        if (! $extension->isPending()) {
            throw new ProjectException(
                'This extension request has already been decided.',
                422,
                'EXTENSION_ALREADY_DECIDED',
                ['extension_request_id' => $extension->id, 'status' => $extension->status],
            );
        }

        if ($decision === MilestoneExtensionRequest::STATUS_APPROVED) {
            $applied = $newDeadline !== null
                ? Carbon::parse($newDeadline)->startOfDay()
                : Carbon::parse((string) $extension->new_due_date)->startOfDay();

            DB::transaction(function () use ($extension, $milestone, $actor, $applied, $reason): void {
                $extension->forceFill([
                    'status' => MilestoneExtensionRequest::STATUS_APPROVED,
                    'decision_reason' => $reason,
                    'applied_deadline' => $applied->toDateString(),
                    'decided_by' => $actor->id,
                    'decided_at' => now(),
                ])->save();

                $milestone->forceFill([
                    'effective_deadline' => $applied->toDateString(),
                    'status' => $milestone->status === ProjectMilestone::STATUS_MISSED
                        ? ProjectMilestone::STATUS_IN_PROGRESS
                        : $milestone->status,
                ])->save();
            });
        } else {
            $extension->forceFill([
                'status' => MilestoneExtensionRequest::STATUS_REJECTED,
                'decision_reason' => $reason,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ])->save();
        }

        $this->audit(
            $actor->id,
            'extension.decided',
            'milestone_extension_request',
            $extension->id,
            ['status' => MilestoneExtensionRequest::STATUS_PENDING],
            [
                'status' => $extension->status,
                'reason' => $reason,
                'applied_deadline' => $extension->applied_deadline?->toDateString(),
                'milestone_id' => $milestone->id,
            ],
            'project_milestone',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        $this->notifications->dispatch(
            userId: (int) $extension->requested_by,
            category: 'project_status',
            title: $extension->status === MilestoneExtensionRequest::STATUS_APPROVED
                ? 'Extension approved'
                : 'Extension rejected',
            body: $milestone->title.': '.($reason ?? 'No reason was provided.'),
            link: '/projects/'.$project->id,
            eventKey: 'extension.decision:'.$extension->id,
        );

        return $extension->fresh();
    }

    public function listForProject(Project $project): Collection
    {
        return MilestoneExtensionRequest::query()
            ->whereIn('project_milestone_id', $project->milestones()->select('id'))
            ->with(['milestone', 'requestedBy'])
            ->latest('id')
            ->get();
    }

    public function payload(MilestoneExtensionRequest $extension): array
    {
        return [
            'id' => $extension->id,
            'milestone_id' => $extension->project_milestone_id,
            'milestone_title' => $extension->milestone?->title,
            'requested_by' => $extension->requested_by,
            'requester_name' => $extension->requestedBy?->name,
            'reason' => $extension->reason,
            'requested_deadline' => $extension->new_due_date?->toDateString(),
            'status' => $extension->status,
            'decision_reason' => $extension->decision_reason,
            'applied_deadline' => $extension->applied_deadline?->toDateString(),
            'decided_by' => $extension->decided_by,
            'decided_at' => $extension->decided_at?->toIso8601String(),
            'created_at' => $extension->created_at?->toIso8601String(),
        ];
    }

    public function cutoffFor(ProjectMilestone $milestone): ?Carbon
    {
        $deadline = $milestone->effectiveDeadline();

        if ($deadline === null) {
            return null;
        }

        $hours = (int) config('sprint5.milestones.extension_cutoff_hours', 0);

        return $deadline->copy()->endOfDay()->subHours(max(0, $hours));
    }
}
