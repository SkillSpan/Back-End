<?php

namespace App\Services\Company;

use App\Models\Application;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Projects\ProjectCapacityPolicy;
use Illuminate\Support\Collection;

class CompanyDashboardService
{
    public function __construct(private readonly ProjectCapacityPolicy $capacityPolicy) {}

    public function summary(Organization $organization, int $userId): array
    {
        $today = now()->toDateString();

        $projectStates = $this->projectStates($organization);
        $seats = $this->occupiedSeats($organization);
        $statusCounts = $this->applicationStatusCounts($organization);
        $expired = $this->expiredApplicationCount($organization, $today);

        $pendingApplications = (int) ($statusCounts[Application::STATUS_SUBMITTED] ?? 0)
            + (int) ($statusCounts[Application::STATUS_SHORTLISTED] ?? 0);

        return [
            'counts' => [
                'projects' => array_sum($projectStates),
                'applicationsPending' => $pendingApplications,
                'unreadNotifications' => $this->unreadNotificationCount($userId),
            ],
            'permissions' => $this->permissions(),
            'tasks' => $this->tasks($organization, $projectStates, $seats, $statusCounts, $today),
            'projectStates' => $projectStates,
            'recentProjects' => $this->recentProjects($organization, $seats, $today),
            'funnel' => $this->funnel($organization, $statusCounts, $expired),
        ];
    }

    private function projectStates(Organization $organization): array
    {
        $states = array_fill_keys(Project::STATUSES, 0);

        $rows = Project::query()
            ->where('organization_id', $organization->id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        foreach ($rows as $status => $count) {
            if (array_key_exists($status, $states)) {
                $states[$status] = (int) $count;
            }
        }

        return $states;
    }

    private function occupiedSeats(Organization $organization): Collection
    {
        return Application::query()
            ->join('projects', 'projects.id', '=', 'applications.project_id')
            ->where('projects.organization_id', $organization->id)
            ->whereIn('applications.status', $this->capacityPolicy->consumingStatuses())
            ->selectRaw('applications.project_id as project_id, count(*) as seats')
            ->groupBy('applications.project_id')
            ->pluck('seats', 'project_id')
            ->map(fn ($seats) => (int) $seats);
    }

    private function applicationStatusCounts(Organization $organization): Collection
    {
        return Application::query()
            ->join('projects', 'projects.id', '=', 'applications.project_id')
            ->where('projects.organization_id', $organization->id)
            ->selectRaw('applications.status as status, count(*) as aggregate')
            ->groupBy('applications.status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count);
    }

    private function expiredApplicationCount(Organization $organization, string $today): int
    {
        return Application::query()
            ->join('projects', 'projects.id', '=', 'applications.project_id')
            ->where('projects.organization_id', $organization->id)
            ->where('applications.status', Application::STATUS_SUBMITTED)
            ->whereNotNull('projects.application_deadline')
            ->where('projects.application_deadline', '<', $today)
            ->count();
    }

    private function funnel(Organization $organization, Collection $statusCounts, int $expired): array
    {
        $submitted = max(0, (int) ($statusCounts[Application::STATUS_SUBMITTED] ?? 0) - $expired);

        $stages = [
            'submitted' => $submitted,
            'shortlisted' => (int) ($statusCounts[Application::STATUS_SHORTLISTED] ?? 0),
            'waitlisted' => (int) ($statusCounts[Application::STATUS_WAITLISTED] ?? 0),
            'accepted' => (int) ($statusCounts[Application::STATUS_ACCEPTED] ?? 0),
            'rejected' => (int) ($statusCounts[Application::STATUS_REJECTED] ?? 0),
            'withdrawn' => (int) ($statusCounts[Application::STATUS_WITHDRAWN] ?? 0),
            'expired' => $expired,
        ];

        $byProject = Application::query()
            ->join('projects', 'projects.id', '=', 'applications.project_id')
            ->where('projects.organization_id', $organization->id)
            ->selectRaw('projects.id as project_id, projects.title as name, count(*) as aggregate')
            ->groupBy('projects.id', 'projects.title')
            ->orderByDesc('aggregate')
            ->limit((int) config('company.dashboard.funnel_project_limit', 5))
            ->get()
            ->map(fn ($row) => ['name' => $row->name, 'count' => (int) $row->aggregate])
            ->all();

        return [
            'total' => array_sum($stages),
            'stages' => $stages,
            'byProject' => $byProject,
        ];
    }

    private function recentProjects(Organization $organization, Collection $seats, string $today): array
    {
        return Project::query()
            ->where('organization_id', $organization->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit((int) config('company.dashboard.recent_projects_limit', 5))
            ->get(['id', 'title', 'type', 'domain', 'status', 'application_deadline', 'capacity', 'updated_at'])
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->title,
                'kind' => $project->type === 'simulation' ? 'simulated' : 'real',
                'track' => $project->domain,
                'state' => $project->status,
                'deadline' => $project->application_deadline === null ? null : [
                    'date' => $project->application_deadline->toDateString(),
                    'closed' => $project->application_deadline->toDateString() < $today,
                ],
                'filled' => (int) ($seats[$project->id] ?? 0),
                'capacity' => (int) $project->capacity,
            ])
            ->all();
    }

    private function tasks(
        Organization $organization,
        array $projectStates,
        Collection $seats,
        Collection $statusCounts,
        string $today,
    ): array {
        $tasks = [];

        $projectLimit = (int) config('company.dashboard.task_project_limit', 5);
        $rowLimit = (int) config('company.dashboard.task_row_limit', 5);
        $windowDays = (int) config('company.dashboard.deadline_window_days', 7);

        $awaiting = Application::query()
            ->join('projects', 'projects.id', '=', 'applications.project_id')
            ->where('projects.organization_id', $organization->id)
            ->whereIn('applications.status', [Application::STATUS_SUBMITTED, Application::STATUS_SHORTLISTED])
            ->selectRaw('projects.id as project_id, projects.title as name, count(*) as aggregate')
            ->groupBy('projects.id', 'projects.title')
            ->orderByDesc('aggregate')
            ->limit($projectLimit)
            ->get();

        $awaitingTotal = (int) ($statusCounts[Application::STATUS_SUBMITTED] ?? 0)
            + (int) ($statusCounts[Application::STATUS_SHORTLISTED] ?? 0);

        if ($awaitingTotal > 0) {
            $tasks[] = [
                'id' => 'review',
                'type' => 'review',
                'tone' => 'blue',
                'count' => $awaitingTotal,
                'projects' => $awaiting
                    ->map(fn ($row) => ['name' => $row->name, 'count' => (int) $row->aggregate])
                    ->all(),
            ];
        }

        if ($projectStates[Project::STATUS_CHANGES_REQUESTED] > 0) {
            $projects = Project::query()
                ->where('organization_id', $organization->id)
                ->where('status', Project::STATUS_CHANGES_REQUESTED)
                ->orderByDesc('updated_at')
                ->limit($rowLimit)
                ->get(['id', 'title']);

            foreach ($projects as $project) {
                $tasks[] = [
                    'id' => 'revise-'.$project->id,
                    'type' => 'revise',
                    'tone' => 'amber',
                    'project' => $project->title,
                    'project_id' => $project->id,
                ];
            }
        }

        $deadlineCutoff = now()->addDays($windowDays)->toDateString();
        $applicationsByProject = $this->applicationsByProject($organization);

        $closing = Project::query()
            ->where('organization_id', $organization->id)
            ->where('status', Project::STATUS_OPEN)
            ->whereNotNull('application_deadline')
            ->whereBetween('application_deadline', [$today, $deadlineCutoff])
            ->orderBy('application_deadline')
            ->limit($rowLimit)
            ->get(['id', 'title', 'application_deadline']);

        foreach ($closing as $project) {
            $tasks[] = [
                'id' => 'deadline-'.$project->id,
                'type' => 'deadline',
                'tone' => 'red',
                'days' => (int) now()->startOfDay()->diffInDays($project->application_deadline->startOfDay()),
                'project' => $project->title,
                'project_id' => $project->id,
                'date' => $project->application_deadline->toDateString(),
                'applications' => (int) ($applicationsByProject[$project->id] ?? 0),
            ];
        }

        if ($projectStates[Project::STATUS_SELECTION] > 0) {
            $selecting = Project::query()
                ->where('organization_id', $organization->id)
                ->where('status', Project::STATUS_SELECTION)
                ->orderByDesc('updated_at')
                ->limit($rowLimit)
                ->get(['id', 'title', 'capacity']);

            foreach ($selecting as $project) {
                $total = (int) $project->capacity;
                $filled = (int) ($seats[$project->id] ?? 0);
                $open = max(0, $total - $filled);

                if ($open === 0) {
                    continue;
                }

                $tasks[] = [
                    'id' => 'roles-'.$project->id,
                    'type' => 'roles',
                    'tone' => 'violet',
                    'open' => $open,
                    'filled' => $filled,
                    'total' => $total,
                    'project' => $project->title,
                    'project_id' => $project->id,
                ];
            }
        }

        if ($projectStates[Project::STATUS_DRAFT] > 0) {
            $drafts = Project::query()
                ->where('organization_id', $organization->id)
                ->where('status', Project::STATUS_DRAFT)
                ->orderByDesc('updated_at')
                ->limit($rowLimit)
                ->get(['id', 'title', 'updated_at']);

            foreach ($drafts as $project) {
                $tasks[] = [
                    'id' => 'draft-'.$project->id,
                    'type' => 'draft',
                    'tone' => 'gray',
                    'count' => 1,
                    'project' => $project->title,
                    'project_id' => $project->id,
                    'date' => $project->updated_at?->toDateString(),
                ];
            }
        }

        return $tasks;
    }

    private function applicationsByProject(Organization $organization): Collection
    {
        return Application::query()
            ->join('projects', 'projects.id', '=', 'applications.project_id')
            ->where('projects.organization_id', $organization->id)
            ->selectRaw('applications.project_id as project_id, count(*) as aggregate')
            ->groupBy('applications.project_id')
            ->pluck('aggregate', 'project_id')
            ->map(fn ($count) => (int) $count);
    }

    private function permissions(): array
    {
        return [
            'can_publish_projects' => true,
            'can_view_projects' => true,
            'can_review_applications' => true,
            'can_manage_profile' => true,
        ];
    }

    private function unreadNotificationCount(int $userId): int
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }
}
