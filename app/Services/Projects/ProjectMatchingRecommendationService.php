<?php

namespace App\Services\Projects;

use App\Exceptions\IntelligenceException;
use App\Models\Project;
use App\Models\ProjectMatchingSnapshot;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Database\QueryException;
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
 *
 * ## Deduplication
 *
 * `project_matching_snapshots` is deliberately append-only — one row per
 * request, so that the validated input state stays a complete audit trail
 * (ProjectMatchingSnapshotService, and its test
 * test_repeated_identical_input_produces_equivalent_snapshot). A new snapshot
 * therefore does NOT imply a new logical recommendation, and keying
 * deduplication on project_matching_snapshot_id would let repeated matching of
 * the same project write a duplicate row every time.
 *
 * The logical identity of a recommendation is instead the canonical
 * fingerprint of its RESULT: learner + project + project_version +
 * algorithm_version + configuration_version + the score, factors, explanation,
 * limiting factors and skill breakdown. Volatile per-request fields
 * (generated_at, the snapshot id) are excluded. Two requests that produce the
 * same logical result therefore collapse to one row, while a genuinely
 * different result — a revised project version, a new algorithm or
 * configuration version, or a changed score — is legitimately kept as its own
 * recommendation rather than overwriting history.
 *
 * The fingerprint is stored in `recommendations.dedup_key` behind a unique
 * index, so uniqueness is enforced by the database, not only by an
 * application-level check.
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

    private const TYPE_PROJECT = 'project';

    /**
     * Store one validated recommendation for the authenticated learner.
     *
     * Idempotent per logical result: repeating the same matching request
     * returns the row that already represents it instead of writing a
     * duplicate. The read-then-write runs in a transaction, and the unique
     * index on dedup_key is the hard guarantee — a concurrent insert that wins
     * the race is detected and its row is reused rather than surfacing a
     * constraint error.
     *
     * @param  array<string, mixed>  $validatedResult  the normalized result returned by
     *                                                 ProjectMatchingService::match()
     *
     * @throws IntelligenceException When the snapshot is not a persisted,
     *                               validated snapshot, does not belong to the
     *                               learner, or the result is malformed or
     *                               inconsistent with the snapshot. Nothing is
     *                               written in any of those cases.
     */
    public function persist(User $learner, ProjectMatchingSnapshot $snapshot, array $validatedResult): Recommendation
    {
        $this->guardPersistableSnapshot($learner, $snapshot);
        $this->guardValidResult($validatedResult);
        $this->guardConsistency($snapshot, $validatedResult);

        $attributes = $this->attributes($snapshot, $validatedResult);
        $dedupKey = $this->dedupKey($attributes);
        $attributes['dedup_key'] = $dedupKey;

        try {
            // No application-level existence pre-check: relying on first()/exists()
            // would leave a TOCTOU window between the check and the insert.
            // The unique index on dedup_key is the guard, and a conflict is
            // resolved by reusing the row that won.
            return DB::transaction(fn () => Recommendation::create($attributes));
        } catch (QueryException $e) {
            if (! $this->isIntegrityConstraintViolation($e)) {
                // Anything that is not an integrity violation is a real
                // failure — never swallow it.
                throw $e;
            }

            // A concurrent request inserted the same logical result first.
            // Reuse its row. When no row carries this key the violation came
            // from something else (a foreign key, a check constraint) and must
            // surface rather than being reported as a successful dedup.
            $existing = $this->findByDedupKey($dedupKey);

            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
    }

    /**
     * Only a persisted, validated snapshot belonging to the authenticated
     * learner may be persisted against — the same rule ProjectMatchingService
     * applies before it will call FastAPI. Ownership is taken from the
     * snapshot's own student profile, never from request input.
     */
    private function guardPersistableSnapshot(User $learner, ProjectMatchingSnapshot $snapshot): void
    {
        if (! $snapshot->exists || $snapshot->status !== ProjectMatchingSnapshot::STATUS_VALIDATED) {
            throw new IntelligenceException(
                'Cannot persist a recommendation for a snapshot that is not validated.',
                422,
                'PROJECT_MATCH_INVALID_SNAPSHOT',
            );
        }

        $studentProfile = $snapshot->studentProfile;

        if ($studentProfile === null) {
            throw new IntelligenceException(
                'Cannot persist a recommendation without the snapshot learner.',
                422,
                'PROJECT_MATCH_INVALID_SNAPSHOT',
            );
        }

        if ((int) $studentProfile->user_id !== (int) $learner->id) {
            throw new IntelligenceException(
                'The matching snapshot does not belong to the authenticated learner.',
                403,
                'PROJECT_MATCH_SNAPSHOT_NOT_OWNED',
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
     * The result must agree with the authoritative Laravel data it claims to
     * describe. The project, its version and both version numbers come from
     * the snapshot Laravel itself built and sent — a result that disagrees
     * with any of them is rejected rather than stored.
     *
     * @param  array<string, mixed>  $result
     */
    private function guardConsistency(ProjectMatchingSnapshot $snapshot, array $result): void
    {
        $this->assertConsistent('project_id', (int) $snapshot->project_id, $result['project_id']);
        $this->assertConsistent('project_version', (int) $snapshot->project_version, $result['project_version']);
        $this->assertConsistent('algorithm_version', (string) $snapshot->algorithm_version, $result['algorithm_version'] ?? null);
        $this->assertConsistent('configuration_version', (string) $snapshot->configuration_version, $result['configuration_version'] ?? null);

        if (! Project::query()->whereKey((int) $result['project_id'])->exists()) {
            throw new IntelligenceException(
                'Cannot persist a recommendation for a project that does not exist.',
                422,
                'PROJECT_MATCH_INCONSISTENT_RESULT',
                ['field' => 'project_id', 'expected' => (int) $result['project_id'], 'actual' => null],
            );
        }
    }

    private function assertConsistent(string $field, mixed $expected, mixed $actual): void
    {
        if ($actual === $expected) {
            return;
        }

        throw new IntelligenceException(
            'The matching result is inconsistent with the snapshot for '.$field.'.',
            422,
            'PROJECT_MATCH_INCONSISTENT_RESULT',
            ['field' => $field, 'expected' => $expected, 'actual' => $actual],
        );
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
            'type' => self::TYPE_PROJECT,
            'candidate_type' => self::TYPE_PROJECT,
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

    /**
     * A stable fingerprint of the logical matching result.
     *
     * generated_at (wall clock) and project_matching_snapshot_id (fresh on
     * every request) are excluded so that repeating the same request does not
     * change the key.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function dedupKey(array $attributes): string
    {
        unset($attributes['generated_at'], $attributes['project_matching_snapshot_id']);

        return hash('sha256', (string) json_encode($this->canonicalize($attributes)));
    }

    /**
     * Sort associative keys recursively so the encoding is order-independent.
     * Lists keep their order — a reordered skill breakdown is a different
     * payload, not a different key ordering.
     */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonicalize($item), $value);
    }

    private function findByDedupKey(string $dedupKey): ?Recommendation
    {
        return Recommendation::query()->where('dedup_key', $dedupKey)->first();
    }

    /**
     * 23000 is the SQLSTATE for an integrity constraint violation on MySQL and
     * SQLite; 23505 is the PostgreSQL unique-violation code.
     */
    private function isIntegrityConstraintViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());

        return $sqlState === '23000' || $sqlState === '23505';
    }
}
