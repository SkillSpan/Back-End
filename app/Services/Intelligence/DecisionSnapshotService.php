<?php

namespace App\Services\Intelligence;

use App\Exceptions\IntelligenceException;
use App\Models\AlgorithmConfiguration;
use App\Models\DecisionSnapshot;

/**
 * US-INT-01 §6/§10 — creates decision snapshots from validated input
 * BEFORE the FastAPI calls, and resolves the active algorithm
 * configuration without ever fabricating one.
 */
class DecisionSnapshotService
{
    public function __construct(
        private readonly IntelligencePayloadBuilder $payloadBuilder,
    ) {}

    /**
     * Resolve the active algorithm configuration or fail explicitly
     * (never invent a default production configuration).
     */
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

    /**
     * Create the decision snapshot from the validated pre-call state.
     * Accepts both payload shapes: the new intelligence contract
     * (learner/role sub-arrays) and the legacy readiness flat payload
     * (top-level student_profile_id/career_role_id).
     */
    public function createPendingSnapshot(
        array $payload,
        string $decisionUuid,
        string $requestId,
    ): DecisionSnapshot {
        $studentProfileId = $payload['learner']['student_profile_id']
            ?? $payload['student_profile_id'];
        $careerRoleId = $payload['role']['id']
            ?? $payload['career_role_id'];
        $careerRoleVersion = $payload['role']['version']
            ?? $payload['career_role_version'];

        return DecisionSnapshot::create([
            'decision_uuid' => $decisionUuid,
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
