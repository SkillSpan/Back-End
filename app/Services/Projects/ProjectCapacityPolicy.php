<?php

namespace App\Services\Projects;

use App\Models\Application;
use App\Models\Project;
use stdClass;

class ProjectCapacityPolicy
{
    public function consumingStatuses(): array
    {
        $statuses = config(
            'project_application.capacity.consuming_statuses',
            Application::CAPACITY_STATUSES
        );

        if (! is_array($statuses) || $statuses === []) {
            $statuses = Application::CAPACITY_STATUSES;
        }

        return array_values(array_unique($statuses));
    }

    public function rejectsSubmissionWhenFull(): bool
    {
        return (bool) config('project_application.capacity.reject_submission_when_full', true);
    }

    public function seatsTaken(Project $project): int
    {
        return $project->applications()
            ->whereIn('status', $this->consumingStatuses())
            ->count();
    }

    public function totalSeats(Project $project): ?int
    {
        return $project->capacity === null ? null : (int) $project->capacity;
    }

    public function seatsRemaining(Project $project): ?int
    {
        $total = $this->totalSeats($project);

        if ($total === null) {
            return null;
        }

        return max(0, $total - $this->seatsTaken($project));
    }

    public function isFull(Project $project): bool
    {
        $remaining = $this->seatsRemaining($project);

        return $remaining !== null && $remaining <= 0;
    }

    public function check(Project $project): object
    {
        $total = $this->totalSeats($project);
        $taken = $this->seatsTaken($project);
        $remaining = $this->seatsRemaining($project);
        $full = $remaining !== null && $remaining <= 0;

        $reasons = [];

        if ($full) {
            $reasons[] = "Project capacity has been reached ({$taken}/{$total} seats awarded).";
        }

        $result = new stdClass;
        $result->capacity = $total;
        $result->seats_taken = $taken;
        $result->seats_remaining = $remaining;
        $result->full = $full;
        $result->consuming_statuses = $this->consumingStatuses();
        $result->reasons = $reasons;

        return $result;
    }

    public function blocksNewApplication(Project $project): bool
    {
        return $this->rejectsSubmissionWhenFull() && $this->isFull($project);
    }
}
