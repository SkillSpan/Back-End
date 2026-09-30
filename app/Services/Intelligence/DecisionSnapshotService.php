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
     *
     * `$flow` records WHICH flow owns the decision. It is promoted to a
     * real column rather than left inside the JSON payload because
     * `GET /api/v1/intelligence/latest` has to *select* by it: both flows
     * write SUCCEEDED snapshots, and only the intelligence flow ever has a
     * roadmap. Selecting the newest succeeded row without this
     * discriminator let a legacy readiness decision shadow the learner's
     * roadmap.
     */
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

    /**
     * Resolve the owning flow for a new snapshot.
     *
     * The explicit argument wins; otherwise the payload's own `flow` key is
     * honoured (that is how the legacy readiness flow has always tagged
     * itself). Anything else falls back to the intelligence flow, which is
     * the default and the only flow the read model treats as primary.
     *
     * An unrecognised value is rejected instead of silently relabelled: a
     * typo'd flow would misattribute the decision, which is precisely the
     * class of bug this column exists to prevent.
     *
     * @param  array<string, mixed>  $payload
     */
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
