<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * US-MATCH-02 — presentation for one project application.
 *
 * Only fields the workflow actually stores are surfaced. `active_key` and
 * `request_fingerprint` are deliberately NOT exposed: they are internal
 * bookkeeping (a uniqueness trick and a body hash), not part of the contract.
 */
class ApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $application = $this->resource;

        return [
            'id' => $application->id,
            'project_id' => $application->project_id,
            'applicant_id' => $application->applicant_id,
            'project_role_id' => $application->project_role_id,
            'project_role' => $this->whenLoaded('projectRole', fn () => $application->projectRole === null ? null : [
                'id' => $application->projectRole->id,
                'title' => $application->projectRole->title,
            ]),
            'status' => $application->status,
            'is_active' => $application->isActive(),
            'project_version' => $application->project_version,
            'application_data' => $application->application_data,
            'recommendation_id' => $application->recommendation_id,
            'recommendation_algorithm_version' => $application->recommendation_algorithm_version,
            'recommendation_configuration_version' => $application->recommendation_configuration_version,
            'decision_reason' => $application->decision_reason,
            'decided_by' => $application->decided_by,
            'decided_at' => $application->decided_at?->toIso8601String(),
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'withdrawn_at' => $application->withdrawn_at?->toIso8601String(),
            'project' => $this->whenLoaded('project', fn () => $application->project === null ? null : new ProjectResource($application->project)),
            'applicant' => $this->whenLoaded('applicant', fn () => $application->applicant === null ? null : [
                'id' => $application->applicant->id,
                'name' => $application->applicant->name,
            ]),
            'created_at' => $application->created_at?->toIso8601String(),
            'updated_at' => $application->updated_at?->toIso8601String(),
        ];
    }
}
