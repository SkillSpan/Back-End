<?php

namespace App\Services\Projects;

use App\Exceptions\IntelligenceException;
use App\Models\ProjectMatchingSnapshot;
use App\Models\Recommendation;
use Illuminate\Support\Facades\DB;

/**
 * Task 11 — persist a validated project matching recommendation.
 *
 * Reuses the existing generic `recommendations` table rather than adding a
 * parallel one: type = 'project', candidate_type = 'project',
 * candidate_id = projects.id. That is the shape the intelligent assistant
 * already reads for its project-recommendation context
 * (AssistantContextBuilder::projectRecommendations()), so persisting here
 * makes stored recommendations visible to existing consumers for free.
 *
 * Persistence runs AFTER Task 10 has already produced and validated the
 * result, inside the same request. No second FastAPI call is made and the
 * matching calculation is never duplicated.
 */
class ProjectMatchingRecommendationService
{
    /**
     * Keys the validated Task 10 result must carry before anything is written.
     *
     * @var list<string>
     */
    private const REQUIRED_RESULT_KEYS = [
        'project_id',
        'project_version',
        'eligibility_state',
        'matching_state',
        'score',
        'factor_scores',
    ];

    /**
     * Store one validated recommendation for the snapshot's learner.
     *
     * Idempotent per snapshot: a repeat call for the same snapshot returns the
     * existing row instead of writing a duplicate. The read-then-write is
     * wrapped in a transaction so concurrent callers cannot interleave, and the
     * unique index on project_matching_snapshot_id is the hard guarantee.
     *
     * @param  array<string, mixed>  $validatedResult  the normalized result returned by
     *                                                 ProjectMatchingService::match()
     *
     * @throws IntelligenceException When the snapshot is not a persisted
     *                               validated snapshot, or the result is
     *                               malformed. Nothing is written either way.
     */
    public function persist(ProjectMatchingSnapshot $snapshot, array $validatedResult): Recommendation
    {
        $this->guardPersistableSnapshot($snapshot);
        $this->guardValidResult($validatedResult);

        return DB::transaction(function () use ($snapshot, $validatedResult) {
            $existing = Recommendation::query()
                ->where('project_matching_snapshot_id', $snapshot->id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return Recommendation::create($this->attributes($snapshot, $validatedResult));
        });
    }

    /**
     * Only a persisted, validated snapshot may be persisted against — the same
     * rule ProjectMatchingService applies before it will call FastAPI.
     */
    private function guardPersistableSnapshot(ProjectMatchingSnapshot $snapshot): void
    {
        if (! $snapshot->exists || $snapshot->status !== ProjectMatchingSnapshot::STATUS_VALIDATED) {
            throw new IntelligenceException(
                'Cannot persist a recommendation for a snapshot that is not validated.',
                422,
                'PROJECT_MATCH_INVALID_SNAPSHOT',
            );
        }

        if ($snapshot->studentProfile === null) {
            throw new IntelligenceException(
                'Cannot persist a recommendation without the snapshot learner.',
                422,
                'PROJECT_MATCH_INVALID_SNAPSHOT',
            );
        }
    }

    /**
     * Refuse anything that is not a complete, well-typed Task 10 result, so a
     * malformed intelligence response can never reach the database.
     *
     * @param  array<string, mixed>  $result
     */
    private function guardValidResult(array $result): void
    {
        foreach (self::REQUIRED_RESULT_KEYS as $key) {
            if (! array_key_exists($key, $result) || $result[$key] === null) {
                throw new IntelligenceException(
                    'Cannot persist an incomplete project matching result.',
                    422,
                    'PROJECT_MATCH_INVALID_RESULT',
                    ['missing_field' => $key],
                );
            }
        }

        if (! is_int($result['project_id']) || $result['project_id'] <= 0) {
            throw new IntelligenceException(
                'Cannot persist a project matching result with an invalid project_id.',
                422,
                'PROJECT_MATCH_INVALID_RESULT',
                ['field' => 'project_id'],
            );
        }

        if (! is_int($result['project_version']) || $result['project_version'] <= 0) {
            throw new IntelligenceException(
                'Cannot persist a project matching result with an invalid project_version.',
                422,
                'PROJECT_MATCH_INVALID_RESULT',
                ['field' => 'project_version'],
            );
        }

        if (! is_numeric($result['score'])) {
            throw new IntelligenceException(
                'Cannot persist a project matching result with an invalid score.',
                422,
                'PROJECT_MATCH_INVALID_RESULT',
                ['field' => 'score'],
            );
        }

        if (! is_array($result['factor_scores'])) {
            throw new IntelligenceException(
                'Cannot persist a project matching result with invalid factor_scores.',
                422,
                'PROJECT_MATCH_INVALID_RESULT',
                ['field' => 'factor_scores'],
            );
        }

        if (! in_array($result['eligibility_state'], ['eligible', 'ineligible'], true)) {
            throw new IntelligenceException(
                'Cannot persist a project matching result with an invalid eligibility_state.',
                422,
                'PROJECT_MATCH_INVALID_RESULT',
                ['field' => 'eligibility_state'],
            );
        }

        if (! in_array($result['matching_state'], ['scored', 'blocked'], true)) {
            throw new IntelligenceException(
                'Cannot persist a project matching result with an invalid matching_state.',
                422,
                'PROJECT_MATCH_INVALID_RESULT',
                ['field' => 'matching_state'],
            );
        }
    }

    /**
     * Map the validated Task 10 result onto the recommendations row.
     *
     * Only fields the validated response actually carries are stored; optional
     * ones stay null rather than being invented.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function attributes(ProjectMatchingSnapshot $snapshot, array $result): array
    {
        $explanation = array_values(array_filter(
            is_array($result['explanation'] ?? null) ? $result['explanation'] : [],
            fn ($line) => is_string($line) && trim($line) !== '',
        ));

        return [
            'user_id' => (int) $snapshot->studentProfile->user_id,
            'project_matching_snapshot_id' => $snapshot->id,
            'type' => 'project',
            'candidate_type' => 'project',
            'candidate_id' => (int) $result['project_id'],
            'score' => (float) $result['score'],
            'factors' => $result['factor_scores'],
            'weighted_contributions' => $result['weighted_contributions'] ?? null,
            // The contract's explanation is the human-readable reason list;
            // `reasons` is the existing text column the assistant already reads.
            'reasons' => $explanation === [] ? null : implode("\n", $explanation),
            'limiting_factors' => $result['limiting_factors'] ?? null,
            'skill_results' => $result['skill_results'] ?? null,
            'algorithm_version' => $result['algorithm_version'] ?? null,
            'configuration_version' => $result['configuration_version'] ?? null,
            'project_version' => (int) $result['project_version'],
            'eligibility_state' => $result['eligibility_state'],
            'matching_state' => $result['matching_state'],
            'generated_at' => now(),
        ];
    }
}
