<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\Models\Recommendation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Task 11 — presentation for a stored project matching recommendation.
 *
 * Only the learner's own recommendations are ever passed here (the query is
 * scoped by user_id), and only fields the validated result actually carried
 * are surfaced — optional ones stay null rather than being invented.
 *
 * @property Recommendation $resource
 */
class RecommendationResource extends JsonResource
{
    /**
     * The candidate project, resolved by the controller in one batched query.
     *
     * `candidate_type` stores a plain string alias ('project'), not a class
     * name, so Recommendation::candidate() cannot be eager-loaded; the
     * controller resolves projects explicitly instead.
     */
    public ?Project $project = null;

    public function toArray(Request $request): array
    {
        $recommendation = $this->resource;

        return [
            'id' => $recommendation->id,
            'type' => $recommendation->type,
            'project_id' => (int) $recommendation->candidate_id,
            'project' => $this->project === null ? null : new ProjectResource($this->project),
            'score' => $recommendation->score !== null ? (float) $recommendation->score : null,
            'eligibility_state' => $recommendation->eligibility_state,
            'matching_state' => $recommendation->matching_state,
            'reasons' => $recommendation->reasons,
            'limiting_factors' => $recommendation->limiting_factors,
            'factors' => $recommendation->factors,
            'weighted_contributions' => $recommendation->weighted_contributions,
            'skill_results' => $recommendation->skill_results,
            'algorithm_version' => $recommendation->algorithm_version,
            'configuration_version' => $recommendation->configuration_version,
            'project_version' => $recommendation->project_version,
            'generated_at' => $recommendation->generated_at?->toIso8601String(),
        ];
    }
}
