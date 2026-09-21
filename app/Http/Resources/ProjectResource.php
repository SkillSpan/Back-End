<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
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
            'status' => $project->status,
            'confidentiality' => $project->confidentiality,
            'version' => $project->version,
            'organization' => $this->whenLoaded('organization', fn () => [
                'id' => $project->organization->id,
                'title' => $project->organization->title,
            ]),
            'required_skills' => $this->whenLoaded('requiredSkills.skill', fn () => $project->requiredSkills->map(fn ($rs) => [
                'skill_id' => $rs->skill_id,
                'skill_name' => $rs->skill?->name,
            ])),
            'created_at' => $project->created_at?->toIso8601String(),
            'updated_at' => $project->updated_at?->toIso8601String(),
        ];
    }
}