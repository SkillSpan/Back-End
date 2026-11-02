<?php

namespace App\Services\Company;

use App\Models\Application;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Projects\ProjectCapacityPolicy;
use Illuminate\Support\Collection;

/**
 * US-COMP-03 — the Company Dashboard summary.
 *
 * Every figure here is computed server-side (BR-03): the dashboard never asks
 * the browser to add up project or application counts. The whole summary is
 * scoped to one organization (BR-02 / AC-13) — the controller resolves that
 * organization from the caller's membership, never from a request parameter,
 * so there is no id a caller could swap for someone else's.
 *
 * Performance (BR-12 / NFR-PERF-06):
 *   - project counts are ONE `group by status` query, not twelve counts;
 *   - occupied seats are ONE aggregate over the organization's applications,
 *     reused by both the recent-projects list and the `roles` task, instead of
 *     calling ProjectCapacityPolicy per project (which would be an N+1);
 *   - the funnel is ONE `group by status` plus ONE count for the expired
 *     slice;
 *   - every list has a configured LIMIT, so no query can scan unboundedly.
 *
 * Funnel data is aggregate only (BR-05 / COMP-06): counts, no applicant ids,
 * names or e-mails ever enter this payload.
 */
class CompanyDashboardService
{
    public function __construct(private readonly ProjectCapacityPolicy $capacityPolicy) {}

    /**
     * The whole dashboard payload for one organization.
     *
     * @return array<string, mixed>
     */
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

    /**
     * Project count per lifecycle state, using the approved status dictionary
     * (BR-04). Every status is present even at zero, so the UI can render a
     * stable grid instead of filling gaps itself.
     *
     * @return array<string, int>
     */
    private function projectStates(Organization $organization): array
    {
        $states = array_fill_keys(Project::STATUSES, 0);

        $rows = Project::query()
            ->where('organization_id', $organization->id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        foreach ($rows as $status => $count) {
            // A status left over from the legacy enum (`closed`) is not part of
            // the approved dictionary and is deliberately not reported.
            if (array_key_exists($status, $states)) {
                $states[$status] = (int) $count;
            }
        }

        return $states;
    }

    /**
     * Occupied seats per project id, in one aggregate.
     *
     * The rule for "what occupies a seat" stays where it already lives —
     * ProjectCapacityPolicy::consumingStatuses() — so this class never becomes
     * a second, drifting definition of capacity.
     *
     * @return Collection<int, int>
     */
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

    /**
     * @return Collection<string, int>
     */
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

    /**
     * Applications still sitting in `submitted` on a project whose deadline has
     * already passed.
     *
     * `expired` is not a value in `applications.status` — nothing writes it —
     * but SRS Table 75 lists it as a funnel stage. The honest reading is "the
     * window closed before a decision was made", so those rows are reported as
     * `expired` and subtracted from `submitted` (they must not be counted
     * twice; a funnel whose stages sum above its total is worse than useless).
     */
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

    /**
     * Section 4 — aggregate funnel. No personal data (BR-05).
     *
     * @param  Collection<string, int>  $statusCounts
     * @return array<string, mixed>
     */
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

    /**
     * Section 3 — the most recently updated projects with their capacity.
     *
     * @param  Collection<int, int>  $seats
     * @return array<int, array<string, mixed>>
     */
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
                // The UI's two words for the project's kind.
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

    /**
     * Section 2 — the company actions that currently need attention.
     *
     * Each row carries the fields its card renders, and its `type` is the key
     * the UI maps to the screen the action is performed on (BR-06 / AC-07).
     * Rows are ordered the way the card presents them.
     *
     * @param  array<string, int>  $projectStates
     * @param  Collection<int, int>  $seats
     * @param  Collection<string, int>  $statusCounts
     * @return array<int, array<string, mixed>>
     */
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

        // t1 — applications awaiting a decision, grouped by project.
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

        // t2 — projects returned for revision. One row per project, because the
        // action ("open the project and fix what the reviewer asked") is per
        // project, not aggregate.
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

        // t3 — open projects whose application deadline is inside the window.
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

        // t4 — projects in selection that still have seats to fill.
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

        // t5 — team invitations awaiting a learner response (US-MATCH-04).
        // There is no invitations table in this codebase yet, so the section is
        // honestly empty rather than fabricated. When US-MATCH-04 lands, this is
        // the one place that has to change.
        // (Deliberately no `invites` row today.)

        // t6 — drafts not yet submitted.
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

    /**
     * Applications (any status) per project, in one aggregate — the deadline
     * task's "N applications" line must not become an N+1.
     *
     * @return Collection<int, int>
     */
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

    /**
     * What this representative may do (BR-07 / AC-12).
     *
     * Sprint 4 has exactly one company role — an admin of an approved
     * organization — and the route already enforces both facts, so every
     * permission is true here. It is still returned explicitly rather than
     * omitted: the UI filters Quick Links on these keys, and a future
     * read-only representative must be able to turn one off without changing
     * the frontend.
     *
     * @return array<string, bool>
     */
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
