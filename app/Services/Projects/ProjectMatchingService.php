<?php

namespace App\Services\Projects;

use App\Exceptions\IntelligenceException;
use App\Models\ProjectMatchingSnapshot;
use App\Services\Intelligence\IntelligenceClient;
use App\Services\Intelligence\IntelligenceResponseValidator;
use Illuminate\Support\Facades\Log;

/**
 * Task 10 — Project Matching FastAPI integration service.
 *
 * Orchestrates the deterministic project-matching recommendation call to the
 * deployed FastAPI intelligence service, reusing the existing
 * IntelligenceClient infrastructure (no duplicate HTTP client).
 *
 * Flow:
 *   1. Receive an authenticated learner + accessible project
 *   2. Validate the existing ProjectMatchingSnapshot (Task 8)
 *   3. Reject invalid or ineligible snapshots
 *   4. Build the request via ProjectMatchingPayloadBuilder (Task 9)
 *   5. Send through IntelligenceClient (POST /api/v1/project-matching)
 *   6. Validate the actual FastAPI response schema
 *   7. Verify request_id + version correlation
 *   8. Normalize the response into a Laravel-consumable result
 *   9. Return the result WITHOUT persisting recommendations
 *
 * The service is gated on config('services.data_science.project_matching_enabled')
 * — disabled yields a 503 INTELLIGENCE_NOT_CONFIGURED rather than a fabricated result.
 */
class ProjectMatchingService
{
    private readonly IntelligenceClient $client;
    private readonly IntelligenceResponseValidator $validator;
    private readonly ProjectMatchingPayloadBuilder $payloadBuilder;

    public function __construct(
        ?IntelligenceClient $client = null,
        ?IntelligenceResponseValidator $validator = null,
        ?ProjectMatchingPayloadBuilder $payloadBuilder = null,
    ) {
        $this->client = $client ?? new IntelligenceClient();
        $this->validator = $validator ?? new IntelligenceResponseValidator();
        $this->payloadBuilder = $payloadBuilder ?? new ProjectMatchingPayloadBuilder();
    }

    /**
     * Calculate a project-matching recommendation for one learner + project.
     *
     * @param  ProjectMatchingSnapshot  $snapshot  a validated snapshot from Task 8
     * @return array<string, mixed> the normalized, validated recommendation
     *
     * @throws IntelligenceException When the service is disabled, the snapshot
     *         is invalid/ineligible, the FastAPI call fails, or the response
     *         fails validation / correlation checks.
     */
    public function match(ProjectMatchingSnapshot $snapshot): array
    {
        $this->guardEnabled();

        $this->guardValidatedSnapshot($snapshot);

        $payload = $this->payloadBuilder->build($snapshot);
        $requestId = (string) $snapshot->request_id;

        $path = (string) config('services.data_science.project_matching_path', '/api/v1/project-matching');

        try {
            $result = $this->client->post($path, $payload, $requestId, 'project_matching');
        } catch (IntelligenceException $e) {
            Log::warning('Project matching request failed.', [
                'request_id' => $requestId,
                'operation' => 'project_matching',
                'failure_code' => $e->codeName,
            ]);

            throw $e;
        }

        $this->validator->validateProjectMatching($result, $requestId);

        $this->validateVersionCorrelation($result, $payload, $snapshot);

        return $this->normalize($result);
    }

    /**
     * The integration may only be invoked when explicitly enabled.
     * Disabled ⇒ 503, never a fabricated result.
     */
    private function guardEnabled(): void
    {
        if (! (bool) config('services.data_science.project_matching_enabled', false)) {
            throw new IntelligenceException(
                'Project matching is not enabled for this intelligence service.',
                503,
                'INTELLIGENCE_NOT_CONFIGURED',
            );
        }
    }

    /**
     * Only validated snapshots may be sent to FastAPI.
     * Pending or failed snapshots are rejected before any network call.
     * Snapshots whose validation indicates the learner is not eligible
     * are also rejected — the snapshot service already prevents creating
     * such snapshots, but this is a defensive check against tampering
     * or stale data.
     */
    private function guardValidatedSnapshot(ProjectMatchingSnapshot $snapshot): void
    {
        if (! $snapshot->exists) {
            throw new IntelligenceException(
                'Cannot match against a snapshot that does not exist.',
                422,
                'PROJECT_MATCH_INVALID_SNAPSHOT',
            );
        }

        if ($snapshot->status !== ProjectMatchingSnapshot::STATUS_VALIDATED) {
            throw new IntelligenceException(
                'Cannot match against a snapshot with status: ' . $snapshot->status . '. Only validated snapshots can be matched.',
                422,
                'PROJECT_MATCH_INVALID_SNAPSHOT',
            );
        }

        $validation = $snapshot->snapshot['validation'] ?? [];
        if (! ($validation['eligible'] ?? false)) {
            throw new IntelligenceException(
                'Cannot match against an ineligible snapshot.',
                422,
                'PROJECT_MATCH_INELIGIBLE',
            );
        }
    }

    /**
     * Verify that the service echoed back the version metadata we sent.
     * The FastAPI contract declares these as optional-with-defaults, so we
     * only check them when the service includes them in the response.
     */
    private function validateVersionCorrelation(array $result, array $payload, ProjectMatchingSnapshot $snapshot): void
    {
        if (array_key_exists('algorithm_version', $result)) {
            $expectedAlgorithm = (string) $payload['algorithm_version'];
            $actualAlgorithm = (string) $result['algorithm_version'];

            if ($actualAlgorithm !== $expectedAlgorithm) {
                throw new IntelligenceException(
                    'The intelligence service returned an inconsistent algorithm_version.',
                    502,
                    'INTELLIGENCE_RESPONSE_MISMATCH',
                    [
                        'expected' => $expectedAlgorithm,
                        'actual' => $actualAlgorithm,
                    ],
                );
            }
        }

        if (array_key_exists('configuration_version', $result)) {
            $expectedConfig = (string) $payload['configuration_version'];
            $actualConfig = (string) $result['configuration_version'];

            if ($actualConfig !== $expectedConfig) {
                throw new IntelligenceException(
                    'The intelligence service returned an inconsistent configuration_version.',
                    502,
                    'INTELLIGENCE_RESPONSE_MISMATCH',
                    [
                        'expected' => $expectedConfig,
                        'actual' => $actualConfig,
                    ],
                );
            }
        }
    }

    /**
     * Normalize the validated FastAPI response into a Laravel-consumable
     * shape. No database writes occur here — recommendations are returned,
     * not persisted.
     *
     * @return array<string, mixed>
     */
    private function normalize(array $result): array
    {
        $recommendation = $result['recommendation'];

        return [
            'request_id' => $result['request_id'],
            'algorithm_version' => $result['algorithm_version'] ?? null,
            'configuration_version' => $result['configuration_version'] ?? null,
            'project_id' => $recommendation['project_id'],
            'project_version' => $recommendation['project_version'],
            'eligibility_state' => $recommendation['eligibility_state'],
            'matching_state' => $recommendation['matching_state'],
            'score' => (float) $recommendation['score'],
            'factor_scores' => $recommendation['factor_scores'],
            'weighted_contributions' => $recommendation['weighted_contributions'],
            'explanation' => $recommendation['explanation'] ?? [],
            'limiting_factors' => $recommendation['limiting_factors'] ?? [],
            'skill_results' => $recommendation['skill_results'] ?? [],
        ];
    }
}
