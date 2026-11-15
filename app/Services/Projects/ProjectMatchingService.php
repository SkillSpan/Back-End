<?php

namespace App\Services\Projects;

use App\Exceptions\IntelligenceException;
use App\Models\ProjectMatchingSnapshot;
use App\Services\Intelligence\IntelligenceClient;
use App\Services\Intelligence\IntelligenceResponseValidator;
use Illuminate\Support\Facades\Log;

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
        $this->client = $client ?? new IntelligenceClient;
        $this->validator = $validator ?? new IntelligenceResponseValidator;
        $this->payloadBuilder = $payloadBuilder ?? new ProjectMatchingPayloadBuilder;
    }

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

        $this->validator->validateProjectMatching($result, $requestId, [
            ...$payload,
            'project_id' => $payload['project']['id'],
        ]);

        return $this->normalize($result);
    }

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
                'Cannot match against a snapshot with status: '.$snapshot->status.'. Only validated snapshots can be matched.',
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
