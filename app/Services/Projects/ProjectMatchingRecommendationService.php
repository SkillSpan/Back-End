<?php

namespace App\Services\Projects;

use App\Exceptions\IntelligenceException;
use App\Models\Project;
use App\Models\ProjectMatchingSnapshot;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ProjectMatchingRecommendationService
{
    private const REQUIRED_RESULT_KEYS = [
        'project_id',
        'project_version',
        'eligibility_state',
        'matching_state',
        'score',
        'factor_scores',
    ];

    private const TYPE_PROJECT = 'project';

    public function persist(User $learner, ProjectMatchingSnapshot $snapshot, array $validatedResult): Recommendation
    {
        $this->guardPersistableSnapshot($learner, $snapshot);
        $this->guardValidResult($validatedResult);
        $this->guardConsistency($snapshot, $validatedResult);

        $attributes = $this->attributes($snapshot, $validatedResult);
        $dedupKey = $this->dedupKey($attributes);
        $attributes['dedup_key'] = $dedupKey;

        try {
            return DB::transaction(fn () => Recommendation::create($attributes));
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->findByDedupKey($dedupKey);

            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
    }

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

    private function dedupKey(array $attributes): string
    {
        unset($attributes['generated_at'], $attributes['project_matching_snapshot_id']);

        return hash('sha256', (string) json_encode($this->canonicalize($attributes)));
    }

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
}
