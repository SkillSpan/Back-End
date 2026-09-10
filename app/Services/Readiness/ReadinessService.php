<?php

namespace App\Services\Readiness;

use App\Exceptions\ReadinessException;
use App\Exceptions\ReadinessIntegrationException;
use App\Models\CareerRole;
use App\Models\DecisionSnapshot;
use App\Models\ReadinessResult;
use App\Models\SkillEvaluation;
use App\Models\SkillGapResult;
use App\Models\StudentProfile;
use App\Services\Intelligence\DecisionSnapshotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReadinessService
{
    public function __construct(
        private readonly DataScienceClient $dataScienceClient,
        private readonly ReadinessPayloadBuilder $payloadBuilder,
        private readonly DecisionSnapshotService $decisionSnapshotService,
    ) {}

    public function calculate(
        StudentProfile $studentProfile,
        ?int $careerRoleId,
        string $requestId
    ): ReadinessResult {
        $careerRoleId ??= $studentProfile->primary_career_role_id;

        if (! $careerRoleId) {
            throw new ReadinessException(
                'A career role is required for readiness calculation.',
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
            $exists = CareerRole::query()
                ->whereKey($careerRoleId)
                ->exists();

            if ($exists) {
                throw new ReadinessException(
                    'The selected career role is not approved for readiness calculation.',
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

        $latestEvaluationsBySkillId = SkillEvaluation::query()
            ->where('student_profile_id', $studentProfile->id)
            ->whereIn('skill_id', $roleSkills->pluck('skill_id'))
            ->orderByDesc('calculated_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy(
                fn (SkillEvaluation $evaluation) => (int) $evaluation->skill_id
            );

        $missingSkillIds = [];

        foreach ($roleSkills as $roleSkill) {
            $latestEvaluation = $latestEvaluationsBySkillId
                ->get((int) $roleSkill->skill_id)
                ?->first();

            $roleSkill->setAttribute(
                'latest_evaluation',
                $latestEvaluation
            );

            if ($latestEvaluation === null) {
                $missingSkillIds[] = (int) $roleSkill->skill_id;
            }
        }

        if ($missingSkillIds !== []) {
            throw new ReadinessIntegrationException(
                'The learner does not have evaluations for all required skills.',
                422,
                'ASSESSMENT_INCOMPLETE',
                ['missing_skill_ids' => $missingSkillIds],
            );
        }

        $payload = $this->payloadBuilder->build(
            $studentProfile,
            $careerRole,
            $roleSkills,
        );

        /*
         * US-INT-01 §10: resolve the active algorithm configuration —
         * an explicit error when none is active, never a fabricated
         * default.
         */
        $configuration = $this->decisionSnapshotService->resolveConfiguration();
        $configurationVersion = 'config-v'.$configuration->version;

        /*
         * US-INT-01 §6: the decision snapshot captures the validated
         * input state BEFORE the FastAPI call (status=pending). The
         * same snapshot is marked succeeded only if the response
         * validates and persists atomically. On failure it stays as
         * the audit record of the attempt — no fabricated results.
         */
        $decisionSnapshot = $this->decisionSnapshotService->createPendingSnapshot(
            array_merge($payload, [
                'career_role_id' => (int) $careerRole->id,
                'career_role_version' => (int) $careerRole->version,
                'student_profile_id' => (int) $studentProfile->id,
                'user_id' => (int) $studentProfile->user_id,
                'target_role' => (string) $careerRole->title,
                'flow' => 'readiness_legacy',
                'algorithm_version' => (string) config('services.data_science.algorithm_version', 'skill-gap-v1'),
                'configuration_version' => $configurationVersion,
            ]),
            (string) Str::uuid(),
            $requestId,
        );

        $result = $this->dataScienceClient->analyze(
            $payload,
            $requestId,
        );

        $this->validateDataScienceResult(
            $result,
            $payload,
            $careerRole,
            $roleSkills,
        );

        $algorithmVersion = (string) $result['algorithm_version'];

        $calculatedAt = now();

        return DB::transaction(function () use (
            $studentProfile,
            $careerRole,
            $result,
            $payload,
            $algorithmVersion,
            $configurationVersion,
            $calculatedAt,
            $requestId,
            $decisionSnapshot,
        ) {
            $snapshot = $decisionSnapshot;

            $snapshot->update([
                'algorithm_version' => $algorithmVersion,
                'status' => DecisionSnapshot::STATUS_SUCCEEDED,
                'calculated_at' => $calculatedAt,
                'snapshot' => [
                    'flow' => 'readiness_legacy',
                    'career_role_id' => (int) $careerRole->id,
                    'career_role_version' => (int) $careerRole->version,
                    'student_profile_id' => (int) $studentProfile->id,
                    'payload' => $payload,
                    'fastapi_result' => $result,
                    'algorithm_version' => $algorithmVersion,
                    'configuration_version' => $configurationVersion,
                    'request_id' => $requestId,
                    'calculated_at' => $calculatedAt->toIso8601String(),
                ],
            ]);

            $readinessResult = ReadinessResult::create([
                'student_profile_id' => $studentProfile->id,
                'career_role_id' => $careerRole->id,
                'career_role_version' => $careerRole->version,
                'decision_snapshot_id' => $snapshot->id,

                'score' => $result['readiness_score'],
                'skill_match_component' => $result['base_readiness_score'],

                'practical_experience_component' => null,
                'assessment_reliability_component' => null,
                'profile_completeness_component' => null,

                'critical_cap_applied' => (bool) $result['critical_skill_cap_applied'],

                'band' => null,

                'algorithm_version' => $algorithmVersion,
                'configuration_version' => $configurationVersion,
                'request_id' => $requestId,
                'calculated_at' => $calculatedAt,
                'snapshot' => [
                    'decision_uuid' => $snapshot->decision_uuid,
                    'payload' => $payload,
                    'fastapi_result' => $result,
                    'algorithm_version' => $algorithmVersion,
                    'configuration_version' => $configurationVersion,
                    'request_id' => $requestId,
                    'calculated_at' => $calculatedAt->toIso8601String(),
                ],
            ]);

            // US-INT-01 §17: validated gap output becomes historical
            // skill_gap_results rows tied to this decision — append-only.
            foreach ($result['skill_results'] as $skillResult) {
                SkillGapResult::create([
                    'decision_snapshot_id' => $snapshot->id,
                    'skill_id' => (int) $skillResult['skill_id'],
                    'current_level' => (float) $skillResult['current_level'],
                    'required_level' => (float) $skillResult['required_level'],
                    'gap' => (float) $skillResult['gap'],
                    'match_score' => isset($skillResult['match_score']) && is_numeric($skillResult['match_score'])
                        ? (float) $skillResult['match_score']
                        : null,
                    'importance_weight' => (float) $skillResult['importance_weight'],
                    'is_critical' => (bool) $skillResult['is_critical'],
                    'confidence' => isset($skillResult['confidence']) && is_numeric($skillResult['confidence'])
                        ? (float) $skillResult['confidence']
                        : null,
                    'status' => (string) $skillResult['status'],
                    'explanation' => $skillResult['explanation'] ?? null,
                ]);
            }

            return $readinessResult;
        });
    }

    public function latest(
        StudentProfile $studentProfile,
        ?int $careerRoleId = null
    ): ?ReadinessResult {
        return ReadinessResult::query()
            ->where('student_profile_id', $studentProfile->id)
            ->when(
                $careerRoleId,
                fn ($query) => $query->where(
                    'career_role_id',
                    $careerRoleId
                )
            )
            ->latest('calculated_at')
            ->latest('id')
            ->first();
    }

    private function validateDataScienceResult(
        array $result,
        array $payload,
        CareerRole $careerRole,
        $roleSkills,
    ): void {
        $required = [
            'student_profile_id',
            'career_role_id',
            'career_role_version',
            'target_role',
            'base_readiness_score',
            'readiness_score',
            'algorithm_version',
            'critical_skill_cap_applied',
            'critical_skill_readiness_cap',
            'critical_skill_gap_count',
            'critical_skill_names',
            'total_skills',
            'met_skills',
            'skills_with_gap',
            'skill_results',
        ];

        foreach ($required as $key) {
            if (! array_key_exists($key, $result)) {
                throw new ReadinessIntegrationException(
                    'The Data Science service returned an incomplete response.',
                    502,
                    'DATA_SCIENCE_INVALID_RESPONSE',
                    ['missing_field' => $key],
                );
            }
        }

        if (
            (int) $result['student_profile_id']
            !== (int) $payload['student_profile_id']
        ) {
            throw new ReadinessIntegrationException(
                'The Data Science response does not match the student profile.',
                502,
                'DATA_SCIENCE_RESPONSE_MISMATCH',
            );
        }

        if (
            (int) $result['career_role_id']
            !== (int) $payload['career_role_id']
        ) {
            throw new ReadinessIntegrationException(
                'The Data Science response does not match the career role.',
                502,
                'DATA_SCIENCE_RESPONSE_MISMATCH',
            );
        }

        if (
            (int) $result['career_role_version']
            !== (int) $payload['career_role_version']
        ) {
            throw new ReadinessIntegrationException(
                'The Data Science response does not match the career role version.',
                502,
                'DATA_SCIENCE_RESPONSE_MISMATCH',
            );
        }

        if (
            (string) $result['target_role']
            !== (string) $careerRole->title
        ) {
            throw new ReadinessIntegrationException(
                'The Data Science response does not match the requested career role.',
                502,
                'DATA_SCIENCE_RESPONSE_MISMATCH',
            );
        }

        if (
            ! is_string($result['algorithm_version'])
            || trim($result['algorithm_version']) === ''
        ) {
            throw new ReadinessIntegrationException(
                'The Data Science response contains an invalid algorithm version.',
                502,
                'DATA_SCIENCE_INVALID_RESPONSE',
                ['field' => 'algorithm_version'],
            );
        }

        foreach ([
            'base_readiness_score',
            'readiness_score',
        ] as $field) {
            if (
                ! is_numeric($result[$field])
                || (float) $result[$field] < 0
                || (float) $result[$field] > 100
            ) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains an invalid readiness score.',
                    502,
                    'DATA_SCIENCE_INVALID_RESPONSE',
                    ['field' => $field],
                );
            }
        }

        if (! is_array($result['skill_results'])) {
            throw new ReadinessIntegrationException(
                'The Data Science response contains invalid skill results.',
                502,
                'DATA_SCIENCE_INVALID_RESPONSE',
            );
        }

        if (
            count($result['skill_results'])
            !== $roleSkills->count()
        ) {
            throw new ReadinessIntegrationException(
                'The Data Science response contains an unexpected number of skill results.',
                502,
                'DATA_SCIENCE_INVALID_RESPONSE',
            );
        }

        $expectedSkills = [];

        foreach ($payload['skills'] as $skill) {
            $expectedSkills[(int) $skill['skill_id']] = $skill;
        }

        $seenSkillIds = [];

        foreach ($result['skill_results'] as $skillResult) {
            foreach ([
                'skill_id',
                'skill_name',
                'current_level',
                'required_level',
                'importance_weight',
                'is_critical',
                'gap',
                'status',
            ] as $field) {
                if (! array_key_exists($field, $skillResult)) {
                    throw new ReadinessIntegrationException(
                        'The Data Science response contains an incomplete skill result.',
                        502,
                        'DATA_SCIENCE_INVALID_RESPONSE',
                        ['missing_field' => $field],
                    );
                }
            }

            $skillId = (int) $skillResult['skill_id'];

            if (! isset($expectedSkills[$skillId])) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains an unknown skill.',
                    502,
                    'DATA_SCIENCE_INVALID_RESPONSE',
                    ['skill_id' => $skillId],
                );
            }

            if (in_array($skillId, $seenSkillIds, true)) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains duplicate skill results.',
                    502,
                    'DATA_SCIENCE_INVALID_RESPONSE',
                    ['skill_id' => $skillId],
                );
            }

            $expected = $expectedSkills[$skillId];

            if ((string) $skillResult['skill_name']
                !== (string) $expected['skill_name']) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains a mismatched skill name.',
                    502,
                    'DATA_SCIENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }

            if (
                abs(
                    (float) $skillResult['current_level']
                    - (float) $expected['current_level']
                ) > 0.0001
            ) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains a mismatched current level.',
                    502,
                    'DATA_SCIENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }

            if (
                abs(
                    (float) $skillResult['required_level']
                    - (float) $expected['required_level']
                ) > 0.0001
            ) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains a mismatched required level.',
                    502,
                    'DATA_SCIENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }

            if (
                abs(
                    (float) $skillResult['importance_weight']
                    - (float) $expected['importance_weight']
                ) > 0.0001
            ) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains a mismatched importance weight.',
                    502,
                    'DATA_SCIENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }

            if (
                (bool) $skillResult['is_critical']
                !== (bool) $expected['is_critical']
            ) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains a mismatched critical flag.',
                    502,
                    'DATA_SCIENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }

            $expectedGap = max(
                (float) $expected['required_level']
                - (float) $expected['current_level'],
                0.0,
            );

            if (
                abs(
                    (float) $skillResult['gap']
                    - $expectedGap
                ) > 0.01
            ) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains a mismatched skill gap.',
                    502,
                    'DATA_SCIENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }

            $expectedStatus =
                $expectedGap == 0.0 ? 'met' : 'gap';

            if (
                (string) $skillResult['status']
                !== $expectedStatus
            ) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains a mismatched skill status.',
                    502,
                    'DATA_SCIENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }

            $seenSkillIds[] = $skillId;
        }

        Log::debug(
            'Validated Data Science readiness response.',
            [
                'career_role_id' => $careerRole->id,
                'career_role_version' => $careerRole->version,
                'result_skill_count' => count($result['skill_results']),
                'algorithm_version' => $result['algorithm_version'],
            ],
        );
    }
}
