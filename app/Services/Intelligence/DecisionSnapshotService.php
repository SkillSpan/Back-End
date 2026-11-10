<?php

namespace App\Services\Intelligence;

use App\Exceptions\IntelligenceException;
use App\Models\AlgorithmConfiguration;
use App\Models\DecisionSnapshot;

class DecisionSnapshotService
{
    public function __construct(
        private readonly IntelligencePayloadBuilder $payloadBuilder,
    ) {}

    public function resolveConfiguration(): AlgorithmConfiguration
    {
        $configuration = AlgorithmConfiguration::query()
            ->where('status', 'active')
            ->orderByDesc('version')
            ->first();

        if (! $configuration) {
            throw new IntelligenceException(
                'No active algorithm configuration is available for intelligence calculations.',
                422,
                'INTELLIGENCE_CONFIGURATION_INVALID',
            );
        }

        return $configuration;
    }

    public function createPendingSnapshot(
        array $payload,
        string $decisionUuid,
        string $requestId,
        ?string $flow = null,
    ): DecisionSnapshot {
        $studentProfileId = $payload['learner']['student_profile_id']
            ?? $payload['student_profile_id'];
        $careerRoleId = $payload['role']['id']
            ?? $payload['career_role_id'];
        $careerRoleVersion = $payload['role']['version']
            ?? $payload['career_role_version'];

        return DecisionSnapshot::create([
            'decision_uuid' => $decisionUuid,
            'flow' => $this->resolveFlow($flow, $payload),
            'student_profile_id' => (int) $studentProfileId,
            'career_role_id' => (int) $careerRoleId,
            'career_role_version' => (int) $careerRoleVersion,
            'algorithm_version' => (string) ($payload['algorithm_version'] ?? 'unresolved'),
            'configuration_version' => (string) ($payload['configuration_version'] ?? 'unresolved'),
            'request_id' => $requestId,
            'snapshot' => $payload,
            'status' => DecisionSnapshot::STATUS_PENDING,
        ]);
    }

    private function resolveFlow(?string $flow, array $payload): string
    {
        $flow ??= is_string($payload['flow'] ?? null) ? $payload['flow'] : null;

        if ($flow === null) {
            return DecisionSnapshot::FLOW_INTELLIGENCE;
        }

        if (! in_array($flow, [
            DecisionSnapshot::FLOW_INTELLIGENCE,
            DecisionSnapshot::FLOW_READINESS_LEGACY,
        ], true)) {
            throw new IntelligenceException(
                'The decision snapshot was created with an unknown flow.',
                500,
                'INTELLIGENCE_INVALID_FLOW',
                ['flow' => $flow],
            );
        }

        return $flow;
    }

    public function markSucceeded(DecisionSnapshot $snapshot): DecisionSnapshot
    {
        $snapshot->update([
            'status' => DecisionSnapshot::STATUS_SUCCEEDED,
            'calculated_at' => now(),
        ]);

        return $snapshot->fresh();
    }

    public function markFailed(DecisionSnapshot $snapshot): DecisionSnapshot
    {
        $snapshot->update(['status' => DecisionSnapshot::STATUS_FAILED]);

        return $snapshot->fresh();
    }
}
