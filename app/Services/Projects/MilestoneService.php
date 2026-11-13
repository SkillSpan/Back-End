<?php

namespace App\Services\Projects;

use App\Exceptions\ProjectException;
use App\Models\MilestoneExtensionRequest;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectMilestoneChange;
use App\Models\Submission;
use App\Models\User;
use App\Services\NotificationService;
use App\Traits\AuditsActions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MilestoneService
{
    use AuditsActions;

    private const TRACKED_FIELDS = [
        'title',
        'description',
        'required_deliverables',
        'submission_type',
        'due_date',
        'effective_deadline',
        'status',
        'order_index',
    ];

    public function __construct(
        private readonly ProjectWorkspaceAccess $access,
        private readonly NotificationService $notifications,
    ) {}

    public function listForProject(Project $project): Collection
    {
        return $project->milestones()
            ->with(['submissions' => fn ($q) => $q->orderByDesc('version')])
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();
    }

    public function create(
        Project $project,
        User $actor,
        array $data,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ProjectMilestone {
        $this->access->assertManager($project, $actor);
        $this->assertProjectAcceptingMilestones($project);

        $existing = $project->milestones()->count();
        $limit = (int) config('sprint5.milestones.max_milestones_per_project', 50);

        if ($existing >= $limit) {
            throw new ProjectException(
                'This project already has the maximum number of milestones.',
                422,
                'MILESTONE_LIMIT_REACHED',
                ['project_id' => $project->id, 'limit' => $limit],
            );
        }

        $milestone = DB::transaction(function () use ($project, $data): ProjectMilestone {
            $dueDate = $data['due_date'] ?? null;

            return $project->milestones()->create([
                'title' => (string) $data['title'],
                'description' => $data['description'] ?? null,
                'required_deliverables' => $this->normalizeDeliverables($data['required_deliverables'] ?? []),
                'submission_type' => $data['submission_type'] ?? ProjectMilestone::TYPE_ANY,
                'due_date' => $dueDate,
                'effective_deadline' => $data['effective_deadline'] ?? $dueDate,
                'status' => ProjectMilestone::STATUS_PENDING,
                'order_index' => (int) ($data['order_index'] ?? ($project->milestones()->max('order_index') + 1)),
            ]);
        });

        $this->audit(
            $actor->id,
            'milestone.created',
            'project_milestone',
            $milestone->id,
            null,
            [
                'project_id' => $project->id,
                'title' => $milestone->title,
                'due_date' => $milestone->due_date?->toDateString(),
                'submission_type' => $milestone->submission_type,
            ],
            'project_milestone',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        $this->notifyTeam(
            $project,
            $actor,
            'New milestone added',
            $milestone->title.' was added to '.$project->title.'.',
            'milestone.created:'.$milestone->id,
        );

        return $milestone->fresh();
    }

    public function update(
        Project $project,
        ProjectMilestone $milestone,
        User $actor,
        array $data,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ProjectMilestone {
        $this->access->assertManager($project, $actor);
        $this->assertBelongsTo($project, $milestone);

        $changes = [];
        $before = [];

        foreach (self::TRACKED_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $old = $milestone->getAttribute($field);
            $new = $data[$field];

            if ($field === 'required_deliverables') {
                $new = $this->normalizeDeliverables((array) $new);
                $old = $this->normalizeDeliverables((array) ($old ?? []));
            }

            if ($this->comparable($old) === $this->comparable($new)) {
                continue;
            }

            $before[$field] = $this->comparable($old);
            $changes[$field] = $new;
        }

        if ($changes === []) {
            return $milestone->fresh();
        }

        DB::transaction(function () use ($milestone, $changes, $actor): void {
            $milestone->fill($changes)->save();

            foreach ($changes as $field => $new) {
                ProjectMilestoneChange::create([
                    'project_milestone_id' => $milestone->id,
                    'actor_id' => $actor->id,
                    'field' => $field,
                    'old_value' => $this->stringValue($before[$field] ?? null),
                    'new_value' => $this->stringValue($this->comparable($new)),
                ]);
            }
        });

        $this->audit(
            $actor->id,
            'milestone.updated',
            'project_milestone',
            $milestone->id,
            $before,
            ['changes' => array_keys($changes), 'project_id' => $project->id],
            'project_milestone',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        $this->notifyTeam(
            $project,
            $actor,
            'Milestone updated',
            $milestone->title.' was updated for '.$project->title.'.',
            null,
        );

        return $milestone->fresh();
    }

    public function reorder(
        Project $project,
        User $actor,
        array $orderedIds,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Collection {
        $this->access->assertManager($project, $actor);

        $owned = $project->milestones()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $requested = array_map('intval', $orderedIds);

        if (array_diff($requested, $owned) !== []) {
            throw new ProjectException(
                'One or more milestones do not belong to this project.',
                422,
                'MILESTONE_NOT_IN_PROJECT',
                ['project_id' => $project->id],
            );
        }

        DB::transaction(function () use ($project, $requested, $actor): void {
            foreach ($requested as $index => $id) {
                $milestone = $project->milestones()->whereKey($id)->first();

                if (! $milestone || (int) $milestone->order_index === $index) {
                    continue;
                }

                ProjectMilestoneChange::create([
                    'project_milestone_id' => $milestone->id,
                    'actor_id' => $actor->id,
                    'field' => 'order_index',
                    'old_value' => (string) $milestone->order_index,
                    'new_value' => (string) $index,
                ]);

                $milestone->forceFill(['order_index' => $index])->save();
            }
        });

        $this->audit(
            $actor->id,
            'milestone.reordered',
            'project',
            $project->id,
            null,
            ['order' => $requested],
            'project_milestone',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $this->listForProject($project);
    }

    public function payload(ProjectMilestone $milestone): array
    {
        $deadline = $milestone->effectiveDeadline();
        $now = now();
        $secondsRemaining = $deadline === null ? null : $deadline->copy()->endOfDay()->timestamp - $now->timestamp;
        $daysRemaining = $secondsRemaining === null ? null : (int) floor($secondsRemaining / 86400);
        $overdue = $secondsRemaining !== null && $secondsRemaining < 0;

        $current = $milestone->relationLoaded('submissions')
            ? $milestone->submissions->firstWhere('is_current', true)
            : $milestone->submissions()->where('is_current', true)->latest('version')->first();

        $submissionCount = $milestone->relationLoaded('submissions')
            ? $milestone->submissions->count()
            : $milestone->submissions()->count();

        $pendingExtension = $milestone->extensionRequests()
            ->where('status', MilestoneExtensionRequest::STATUS_PENDING)
            ->latest('id')
            ->first();

        $approvedExtension = $milestone->extensionRequests()
            ->where('status', MilestoneExtensionRequest::STATUS_APPROVED)
            ->latest('id')
            ->first();

        return [
            'id' => $milestone->id,
            'project_id' => $milestone->project_id,
            'title' => $milestone->title,
            'description' => $milestone->description,
            'required_deliverables' => $milestone->requiredDeliverables(),
            'submission_type' => $milestone->submission_type,
            'due_date' => $milestone->due_date?->toDateString(),
            'effective_deadline' => $deadline?->toDateString(),
            'extended' => $milestone->effective_deadline !== null
                && $milestone->due_date !== null
                && $milestone->effective_deadline->toDateString() !== $milestone->due_date->toDateString(),
            'status' => $milestone->status,
            'completion_state' => $milestone->status === ProjectMilestone::STATUS_COMPLETED ? 'completed' : 'pending',
            'order_index' => (int) $milestone->order_index,
            'countdown' => [
                'days_remaining' => $daysRemaining,
                'is_overdue' => $overdue,
                'label' => $this->countdownLabel($daysRemaining, $overdue),
            ],
            'submissions_count' => $submissionCount,
            'current_submission' => $current === null ? null : [
                'id' => $current->id,
                'version' => (int) $current->version,
                'submitted_at' => $current->submitted_at?->toIso8601String(),
                'status' => $current->status,
            ],
            'extension' => [
                'pending_request_id' => $pendingExtension?->id,
                'pending_requested_deadline' => $pendingExtension?->new_due_date?->toDateString(),
                'approved_request_id' => $approvedExtension?->id,
                'approved_deadline' => $approvedExtension?->applied_deadline?->toDateString(),
            ],
        ];
    }

    public function payloadCollection(Collection $milestones): array
    {
        return $milestones->map(fn (ProjectMilestone $milestone) => $this->payload($milestone))->values()->all();
    }

    public function markSubmitted(ProjectMilestone $milestone, Submission $submission): void
    {
        if ($milestone->status === ProjectMilestone::STATUS_COMPLETED) {
            return;
        }

        $milestone->forceFill(['status' => ProjectMilestone::STATUS_SUBMITTED])->save();
    }

    public function assertBelongsTo(Project $project, ProjectMilestone $milestone): void
    {
        if ((int) $milestone->project_id !== (int) $project->id) {
            throw new ProjectException(
                'This milestone does not belong to the selected project.',
                404,
                'MILESTONE_NOT_FOUND',
                ['milestone_id' => $milestone->id, 'project_id' => $project->id],
            );
        }
    }

    private function assertProjectAcceptingMilestones(Project $project): void
    {
        if (in_array($project->status, [Project::STATUS_COMPLETED, Project::STATUS_ARCHIVED, Project::STATUS_CANCELLED], true)) {
            throw new ProjectException(
                'Milestones cannot be changed on a closed project.',
                422,
                'MILESTONE_PROJECT_CLOSED',
                ['project_id' => $project->id, 'status' => $project->status],
            );
        }
    }

    private function notifyTeam(Project $project, User $actor, string $title, string $body, ?string $eventKey): void
    {
        foreach ($this->access->memberIds($project) as $memberId) {
            if ($memberId === (int) $actor->id) {
                continue;
            }

            $this->notifications->dispatch(
                userId: $memberId,
                category: 'project_status',
                title: $title,
                body: $body,
                link: '/projects/'.$project->id,
                eventKey: $eventKey === null ? null : $eventKey.':'.$memberId,
            );
        }
    }

    private function countdownLabel(?int $days, bool $overdue): string
    {
        if ($days === null) {
            return 'No deadline set';
        }

        if ($overdue) {
            return 'Deadline passed';
        }

        if ($days === 0) {
            return 'Due today';
        }

        if ($days === 1) {
            return '1 day left';
        }

        return $days.' days left';
    }

    private function normalizeDeliverables(array $deliverables): array
    {
        return array_values(array_filter(
            array_map(fn ($item) => is_string($item) ? trim($item) : '', $deliverables),
            fn (string $item) => $item !== '',
        ));
    }

    private function comparable(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_array($value)) {
            return array_values($value);
        }

        return $value;
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn ($item) => (string) $item, $value));
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
