<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public ?array $eligibility = null;

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
            'career_role_id' => $project->career_role_id,
            'career_role' => $this->whenLoaded('careerRole', fn () => $project->careerRole === null ? null : [
                'id' => $project->careerRole->id,
                'title' => $project->careerRole->title,
                'slug' => $project->careerRole->slug,
                'version' => $project->careerRole->version,
            ]),
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
                'title' => $project->organization->name,
            ]),
            'required_skills' => $this->whenLoaded('requiredSkills', fn () => $project->requiredSkills->map(fn ($rs) => [
                'skill_id' => $rs->skill_id,
                'skill_name' => $rs->skill?->name,
                'minimum_level' => (float) $rs->minimum_level,
                'minimum_required_level' => (float) $rs->minimum_level,
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

    private function durationDays(): ?int
    {
        $project = $this->resource;

        if ($project->start_date === null || $project->end_date === null) {
            return null;
        }

        return (int) $project->start_date->diffInDays($project->end_date);
    }
}
