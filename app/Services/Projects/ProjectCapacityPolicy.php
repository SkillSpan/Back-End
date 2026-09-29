<?php

namespace App\Services\Projects;

use App\Models\Application;
use App\Models\Project;
use stdClass;

/**
 * US-MATCH-02 — the single, swappable seam for project capacity.
 *
 * WHY THIS CLASS EXISTS
 * ---------------------
 * `projects.capacity` is the confirmed source of truth, but the RULE around it
 * (which statuses occupy a seat, and whether a full project blocks a new
 * submission) was explicitly NOT approved when the workflow was designed. It is
 * therefore isolated here and read from config, so the rule can change with a
 * config edit — no schema change, no rewrite of ApplicationService.
 *
 * This is the only place in the codebase that decides what "full" means.
 * ApplicationService must call this policy rather than counting applications
 * itself.
 *
 * BUSINESS MEANING (confirmed 2026-09-29): capacity represents ACTUAL PROJECT
 * SEATS / PARTICIPATION capacity. It is NOT a cap on how many learners may
 * discover or apply for the project, and it must never be used as a career-role
 * or skill eligibility mechanism.
 *
 * The shape of check() deliberately mirrors
 * ProjectAvailabilityService::check() (an object with boolean flags plus a
 * `reasons` array) so callers handle capacity exactly like availability.
 */
class ProjectCapacityPolicy
{
    /**
     * Statuses that occupy a seat. Read from config; falls back to the
     * documented default constant so the policy still works if the config key
     * is missing.
     *
     * @return array<int, string>
     */
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

    /**
     * Whether a project with no seats left should refuse a NEW submission.
     */
    public function rejectsSubmissionWhenFull(): bool
    {
        return (bool) config('project_application.capacity.reject_submission_when_full', true);
    }

    /**
     * Seats currently occupied.
     */
    public function seatsTaken(Project $project): int
    {
        return $project->applications()
            ->whereIn('status', $this->consumingStatuses())
            ->count();
    }

    /**
     * Total seats, or null when the project is unlimited.
     */
    public function totalSeats(Project $project): ?int
    {
        return $project->capacity === null ? null : (int) $project->capacity;
    }

    /**
     * Remaining seats, or null when the project is unlimited.
     */
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

    /**
     * Full capacity report for a project.
     */
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

    /**
     * Whether a new application must be refused because the project is full.
     * This is the single call ApplicationService should make.
     */
    public function blocksNewApplication(Project $project): bool
    {
        return $this->rejectsSubmissionWhenFull() && $this->isFull($project);
    }
}
