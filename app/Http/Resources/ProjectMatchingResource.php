<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectMatchingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $result = $this->resource;

        return [
            'request_id' => $result['request_id'],
            'algorithm_version' => $result['algorithm_version'],
            'configuration_version' => $result['configuration_version'],
            'project_id' => $result['project_id'],
            'project_version' => $result['project_version'],
            'eligibility_state' => $result['eligibility_state'],
            'matching_state' => $result['matching_state'],
            'score' => $result['score'],
            'factor_scores' => $result['factor_scores'],
            'weighted_contributions' => $result['weighted_contributions'],
            'explanation' => $result['explanation'],
            'limiting_factors' => $result['limiting_factors'],
            'skill_results' => $result['skill_results'],
        ];
    }
}
