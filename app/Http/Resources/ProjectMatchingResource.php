<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Task 10 — presentation for POST /api/v1/projects/{project}/match.
 *
 * Exposes only the normalized, already-validated FastAPI recommendation.
 * The raw upstream body and the internal ProjectMatchingSnapshot are
 * deliberately NOT exposed (same policy as IntelligenceResultResource).
 *
 * `request_id` here is the correlation id that was sent to FastAPI and
 * echoed back — i.e. the ProjectMatchingSnapshot's request id. It is a
 * different value from the HTTP envelope's top-level `request_id`, which
 * is the caller's X-Request-ID (see ProjectMatchingController).
 *
 * @property array<string, mixed> $resource
 */
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
