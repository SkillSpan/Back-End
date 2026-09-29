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
 * ## Redaction
 *
 * The embedded project is resolved by the controller through
 * ProjectAccessService, so `$project === null` means the learner can no longer
 * access it — because membership was revoked, the project was made restricted
 * to another organization, it was closed or expired, or it no longer exists.
 *
 * In that case the row is still returned (the learner's historical
 * recommendation is preserved and stays identifiable by `id`/`project_id`/
 * `generated_at`), but EVERY result-derived field is redacted: score, reasons,
 * limiting factors, factors, weighted contributions, skill breakdown, all
 * three version numbers, the eligibility/matching states, and the project
 * payload. Redacting only some of them would leak the matching outcome through
 * the rest. `access_revoked` makes the withholding explicit, so a client can
 * tell "deliberately withheld" from "the algorithm returned nothing".
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

        $redacted = $this->project === null;

        // Nulls every result-derived value once access is gone.
        $sensitive = fn (mixed $value) => $redacted ? null : $value;

        // Key order is identical in both branches so the response shape stays
        // stable for clients.
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
