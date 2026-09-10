<?php

namespace App\Services\Intelligence;

use App\Exceptions\IntelligenceException;
use App\Exceptions\ReadinessException;
use App\Models\CareerRole;
use App\Models\LearnerSkill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * US-INT-01 §22 — the intelligence orchestration flow:
 *
 *  1. validate role (approved) + required skills
 *  2. gather validated learner skill state (single queries, no N+1)
 *  3. resolve algorithm/configuration versions
 *  4. build payload + persist the PENDING decision snapshot (pre-call)
 *  5. FastAPI skill-gap → validate
 *  6. FastAPI readiness  → validate
 *  7. FastAPI roadmap     → validate (when enabled)
 *  8. persist the COMPLETE decision atomically
 *
 * Laravel stays authoritative: no result is trusted before validation,
 * and nothing is persisted unless every enabled call succeeded.
 */
class IntelligenceService
{
    public function __construct(
        private readonly IntelligenceClient $client,
        private readonly IntelligencePayloadBuilder $payloadBuilder,
        private readonly IntelligenceResponseValidator $validator,
        private readonly DecisionSnapshotService $snapshotService,
        private readonly IntelligencePersistenceService $persistenceService,
    ) {}

    /**
     * Full intelligence calculation for a learner + career role.
     *
     * @return array{snapshot: mixed, readiness: mixed, skill_gaps: list<mixed>, roadmap: mixed}
     */
    public function calculate(StudentProfile $studentProfile, ?int $careerRoleId, string $requestId): array
    {
        $careerRoleId ??= $studentProfile->primary_career_role_id;

        if (! $careerRoleId) {
            throw new ReadinessException(
                'A career role is required for intelligence calculation.',
                422,
                'CAREER_ROLE_REQUIRED',
            );
        }

        $careerRole = CareerRole::query()
            ->whereKey($careerRoleId)
            ->where('status', 'approved')
            ->with('roleSkills.skill')
            ->first();

        if (! $careerRole) {
            $exists = CareerRole::query()->whereKey($careerRoleId)->exists();

            if ($exists) {
                throw new ReadinessException(
                    'The selected career role is not approved for intelligence calculation.',
                    422,
                    'CAREER_ROLE_NOT_APPROVED',
                );
            }

            throw new ReadinessException(
                'The selected career role does not exist.',
                404,
                'CAREER_ROLE_NOT_FOUND',
                ['career_role_id' => $careerRoleId],
            );
        }

        $roleSkills = $careerRole->roleSkills;

        if ($roleSkills->isEmpty()) {
            throw new ReadinessException(
                'The selected career role has no required skills.',
                422,
                'CAREER_ROLE_NO_SKILLS',
            );
        }

        $skillIds = $roleSkills->pluck('skill_id')->all();

        // One query per concern (US-INT-01 §30 — no N+1).
        $learnerSkills = LearnerSkill::query()
            ->where('learner_id', $studentProfile->user_id)
            ->whereIn('skill_id', $skillIds)
            ->get();

        $evaluations = SkillEvaluation::query()
            ->where('student_profile_id', $studentProfile->id)
            ->whereIn('skill_id', $skillIds)
            ->orderByDesc('calculated_at')
            ->orderByDesc('id')
            ->get();

        if ($evaluations->isEmpty()) {
            $missingSkillIds = $roleSkills
                ->pluck('skill_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            throw new ReadinessException(
                'The learner does not have evaluations for all required skills.',
                422,
                'ASSESSMENT_INCOMPLETE',
                ['missing_skill_ids' => $missingSkillIds],
            );
        }

        $evidenceSummary = $this->evidenceSummary($studentProfile, $skillIds);

        // Resolve versions — configuration is NEVER fabricated.
        $configuration = $this->snapshotService->resolveConfiguration();
        $configurationVersion = 'config-v'.$configuration->version;

        $skillNameById = [];
        foreach ($roleSkills as $roleSkill) {
            $skillNameById[(int) $roleSkill->skill_id] = (string) $roleSkill->skill?->name;
        }

        // The payload builder validates every level before sending.
        $payload = $this->payloadBuilder->build(
            $studentProfile,
            $careerRole,
            $roleSkills,
            $learnerSkills,
            $evaluations,
            $evidenceSummary,
            (string) config('services.data_science.algorithm_version', 'skill-gap-v1'),
            $configurationVersion,
        );

        $decisionUuid = (string) Str::uuid();

        // The snapshot is persisted BEFORE the FastAPI calls, capturing
        // the validated input state (US-INT-01 §6).
        $snapshot = $this->snapshotService->createPendingSnapshot(
            $payload,
            $decisionUuid,
            $requestId,
        );

        try {
            $skillGap = $this->client->calculateSkillGap($payload, $requestId);
            $this->validator->validateSkillGap($skillGap, $payload);

            $readiness = $this->client->calculateReadiness($payload, $requestId);
            $this->validator->validateReadiness($readiness, $payload);

            $roadmap = null;

            if (config('services.data_science.roadmap_enabled', false)) {
                $roadmapPayload = array_merge($payload, ['skill_gap_result' => $skillGap]);
                $roadmap = $this->client->generateRoadmap($roadmapPayload, $requestId);
                $this->validator->validateRoadmap($roadmap, $payload);
            }

            // Algorithm version comes from the validated service
            // responses — the same value across all of them.
            $algorithmVersion = (string) ($skillGap['algorithm_version']
                ?? $readiness['algorithm_version']
                ?? $payload['algorithm_version']);

            $result = $this->persistenceService->persistCompleteDecision(
                $snapshot,
                $skillGap,
                $readiness,
                $roadmap,
                $skillNameById,
                $algorithmVersion,
                $configurationVersion,
                $requestId,
            );

            Log::info('Intelligence decision completed.', [
                'request_id' => $requestId,
                'decision_uuid' => $decisionUuid,
                'student_profile_id' => $studentProfile->id,
                'career_role_id' => $careerRole->id,
                'career_role_version' => $careerRole->version,
                'algorithm_version' => $algorithmVersion,
                'configuration_version' => $configurationVersion,
                'readiness_score' => $readiness['readiness_score'] ?? null,
                'roadmap_generated' => $roadmap !== null,
            ]);

            return $result;
        } catch (IntelligenceException|ReadinessException $e) {
            // The attempt is recorded as failed — historical rows stay
            // untouched, and nothing fabricated is persisted.
            $this->snapshotService->markFailed($snapshot);

            Log::warning('Intelligence decision failed.', [
                'request_id' => $requestId,
                'decision_uuid' => $decisionUuid,
                'student_profile_id' => $studentProfile->id,
                'career_role_id' => $careerRole->id,
                'failure_code' => $e->codeName,
            ]);

            throw $e;
        } catch (Throwable $e) {
            $this->snapshotService->markFailed($snapshot);

            Log::error('Intelligence decision failed unexpectedly.', [
                'request_id' => $requestId,
                'decision_uuid' => $decisionUuid,
                'student_profile_id' => $studentProfile->id,
                'career_role_id' => $careerRole->id,
                'failure_reason' => $e->getMessage(),
            ]);

            throw new IntelligenceException(
                'The intelligence calculation could not be completed.',
                502,
                'INTELLIGENCE_CALCULATION_FAILED',
                [],
                $e,
            );
        }
    }

    /**
     * Evidence summary per skill — counts by verification state, no
     * evidence contents, no PII (only the latest reference per skill).
     *
     * @param  list<int>  $skillIds
     * @return array<int, array<string, mixed>>
     */
    private function evidenceSummary(StudentProfile $studentProfile, array $skillIds): array
    {
        $summary = [];

        $evidences = $studentProfile->skillEvidences()
            ->whereIn('skill_id', $skillIds)
            ->orderByDesc('evidence_date')
            ->get()
            ->groupBy('skill_id');

        foreach ($evidences as $skillId => $items) {
            /** @var Collection $items */
            $summary[(int) $skillId] = [
                'total' => $items->count(),
                'verified' => $items->where('verification_status', 'verified')->count(),
                'pending' => $items->where('verification_status', 'pending')->count(),
                'rejected' => $items->where('verification_status', 'rejected')->count(),
                'latest_reference' => $items->first()?->reference,
                'latest_evidence_date' => $items->first()?->evidence_date?->toDateString(),
            ];
        }

        return $summary;
    }
}
