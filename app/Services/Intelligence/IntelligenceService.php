<?php

namespace App\Services\Intelligence;

use App\Exceptions\IntelligenceException;
use App\Exceptions\ReadinessException;
use App\Models\CareerRole;
use App\Models\DecisionSnapshot;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Support\SafeLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class IntelligenceService
{
    public function __construct(
        private readonly IntelligenceClient $client,
        private readonly IntelligencePayloadBuilder $payloadBuilder,
        private readonly IntelligenceResponseValidator $validator,
        private readonly DecisionSnapshotService $snapshotService,
        private readonly IntelligencePersistenceService $persistenceService,
    ) {}

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
            ->with(['roleSkills.skill', 'roleSkills.prerequisites'])
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

        $evaluations = SkillEvaluation::query()
            ->where('student_profile_id', $studentProfile->id)
            ->whereIn('skill_id', $skillIds)
            ->orderByDesc('calculated_at')
            ->orderByDesc('id')
            ->get();

        $evaluatedSkillIds = $evaluations
            ->map(fn (SkillEvaluation $evaluation) => (int) $evaluation->skill_id)
            ->unique()
            ->all();

        $missingSkillIds = [];

        foreach ($roleSkills as $roleSkill) {
            if (! in_array((int) $roleSkill->skill_id, $evaluatedSkillIds, true)) {
                $missingSkillIds[] = (int) $roleSkill->skill_id;
            }
        }

        if ($missingSkillIds !== []) {
            throw new ReadinessException(
                'The learner does not have evaluations for all required skills.',
                422,
                'ASSESSMENT_INCOMPLETE',
                ['missing_skill_ids' => $missingSkillIds],
            );
        }

        $evidenceSummary = $this->evidenceSummary($studentProfile, $skillIds);

        $configuration = $this->snapshotService->resolveConfiguration();
        $configurationVersion = 'config-v'.$configuration->version;

        $skillNameById = [];
        foreach ($roleSkills as $roleSkill) {
            $skillNameById[(int) $roleSkill->skill_id] = (string) $roleSkill->skill?->name;
        }

        $payload = $this->payloadBuilder->build(
            $studentProfile,
            $careerRole,
            $roleSkills,
            $evaluations,
            $evidenceSummary,
            (string) config('services.data_science.algorithm_version', 'skill-gap-v1'),
            $configurationVersion,
        );

        $decisionUuid = (string) Str::uuid();

        $snapshot = $this->snapshotService->createPendingSnapshot(
            $payload,
            $decisionUuid,
            $requestId,
            DecisionSnapshot::FLOW_INTELLIGENCE,
        );

        try {
            $skillGap = $this->client->calculateSkillGap($payload, $requestId);
            $this->validator->validateSkillGap($skillGap, $payload);

            $readiness = $skillGap;
            $this->validator->validateReadiness($readiness, $payload);

            $roadmap = null;

            if (config('services.data_science.roadmap_enabled', false)) {
                $roadmapPayload = array_merge($payload, ['skill_gap_result' => $skillGap]);
                $roadmap = $this->client->generateRoadmap($roadmapPayload, $requestId);
                $this->validator->validateRoadmap($roadmap, $payload);
            }

            $algorithmVersion = (string) ($skillGap['algorithm_version']
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
                'failure_reason' => SafeLog::reason($e),
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

    private function evidenceSummary(StudentProfile $studentProfile, array $skillIds): array
    {
        $summary = [];

        $evidences = $studentProfile->skillEvidences()
            ->whereIn('skill_id', $skillIds)
            ->orderByDesc('evidence_date')
            ->get()
            ->groupBy('skill_id');

        foreach ($evidences as $skillId => $items) {
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
