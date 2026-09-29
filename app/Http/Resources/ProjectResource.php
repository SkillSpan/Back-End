<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * US-MATCH-02 — project presentation.
 *
 * The base field set is unchanged, so the catalog list, the recommendation
 * payload and every existing consumer keep the same contract.
 *
 * Three OPTIONAL blocks were added for the project-details endpoint:
 *
 *   - `duration_days`         — pure derivation from start_date/end_date.
 *   - `available_project_roles` — the active project_roles, present whenever the
 *                               relation is loaded.
 *   - `eligibility` / `capacity_state` — attached by the controller ONLY on the
 *                               details endpoint, because they are per-learner
 *                               and per-request: computing them for a 50-project
 *                               catalog page would run 50 eligibility checks.
 *
 * Nothing here decides eligibility or capacity. Both come from the existing
 * ProjectEligibilityService / ProjectCapacityPolicy, and both are omitted
 * (not defaulted to "true"/"available") when the controller has not computed
 * them — an absent block must never be read as a positive answer.
 */
class ProjectResource extends JsonResource
{
    /** Per-learner eligibility, computed by the controller. Null = not computed. */
    public ?array $eligibility = null;

    /** Capacity report from ProjectCapacityPolicy. Null = not computed. */
    public ?array $capacityState = null;

    public function toArray(Request $request): array
    {
        $project = $this->resource;

        return [
            'id' => $project->id,
            'title' => $project->title,
            'description' => $project->description,
            'type' => $project->type,
            'domain' => $project->domain,
            'objectives' => $project->objectives,
            'learning_outcomes' => $project->learning_outcomes,
            'difficulty' => $project->difficulty,
            'work_mode' => $project->work_mode,
            'role' => $project->role,
            'schedule' => $project->schedule,
            'capacity' => $project->capacity,
            'min_team_size' => $project->min_team_size,
            'application_deadline' => $project->application_deadline?->toDateString(),
            'start_date' => $project->start_date?->toDateString(),
            'end_date' => $project->end_date?->toDateString(),
            'duration_days' => $this->durationDays(),
            'status' => $project->status,
            'confidentiality' => $project->confidentiality,
            'version' => $project->version,
            'organization' => $this->whenLoaded('organization', fn () => [
                'id' => $project->organization->id,
                'title' => $project->organization->title,
            ]),
            'required_skills' => $this->whenLoaded('requiredSkills', fn () => $project->requiredSkills->map(fn ($rs) => [
                'skill_id' => $rs->skill_id,
                'skill_name' => $rs->skill?->name,
                'minimum_level' => (float) $rs->minimum_level,
                'is_critical_entry' => (bool) $rs->is_critical_entry,
            ])),
            'available_project_roles' => $this->whenLoaded('projectRoles', fn () => $project->projectRoles
                ->where('is_active', true)
                ->values()
                ->map(fn ($role) => [
                    'id' => $role->id,
                    'title' => $role->title,
                    'description' => $role->description,
                ])),
            'eligibility' => $this->eligibility,
            'capacity_state' => $this->capacityState,
            'created_at' => $project->created_at?->toIso8601String(),
            'updated_at' => $project->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Whole days between start_date and end_date, or null when either is
     * missing. Derived from the stored dates — no duration policy is invented.
     */
    private function durationDays(): ?int
    {
        $project = $this->resource;

        if ($project->start_date === null || $project->end_date === null) {
            return null;
        }

        return (int) $project->start_date->diffInDays($project->end_date);
    }
}
