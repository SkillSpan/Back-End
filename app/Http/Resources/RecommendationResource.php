<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecommendationResource extends JsonResource
{
    public ?Project $project = null;

    public function toArray(Request $request): array
    {
        $recommendation = $this->resource;

        $redacted = $this->project === null;

        $sensitive = fn (mixed $value) => $redacted ? null : $value;

        return [
            'id' => $recommendation->id,
            'type' => $recommendation->type,
            'project_id' => (int) $recommendation->candidate_id,
            'access_revoked' => $redacted,
            'project' => $redacted ? null : new ProjectResource($this->project),
            'score' => $sensitive($recommendation->score !== null ? (float) $recommendation->score : null),
            'eligibility_state' => $sensitive($recommendation->eligibility_state),
            'matching_state' => $sensitive($recommendation->matching_state),
            'reasons' => $sensitive($recommendation->reasons),
            'limiting_factors' => $sensitive($recommendation->limiting_factors),
            'factors' => $sensitive($recommendation->factors),
            'weighted_contributions' => $sensitive($recommendation->weighted_contributions),
            'skill_results' => $sensitive($recommendation->skill_results),
            'algorithm_version' => $sensitive($recommendation->algorithm_version),
            'configuration_version' => $sensitive($recommendation->configuration_version),
            'project_version' => $sensitive($recommendation->project_version),
            'generated_at' => $recommendation->generated_at?->toIso8601String(),
        ];
    }
}
