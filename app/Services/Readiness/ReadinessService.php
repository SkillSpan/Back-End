<?php

namespace App\Services\Readiness;

use App\Exceptions\ReadinessException;
use App\Exceptions\ReadinessIntegrationException;
use App\Models\CareerRole;
use App\Models\ReadinessResult;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReadinessService
{
    public function __construct(
        private readonly DataScienceClient $dataScienceClient,
        private readonly ReadinessPayloadBuilder $payloadBuilder,
        private readonly PracticalExperienceService $practicalExperienceService,
        private readonly AssessmentReliabilityService $assessmentReliabilityService,
        private readonly ProfileCompletenessService $profileCompletenessService,
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

        $practicalExperience = $this->practicalExperienceService->calculate($studentProfile);
        $assessmentReliability = $this->assessmentReliabilityService->calculate($studentProfile);
        $profileCompleteness = $this->profileCompletenessService->calculate($studentProfile);

        if ($practicalExperience['score'] === null || $assessmentReliability['score'] === null) {
            throw new ReadinessIntegrationException(
                'Readiness cannot be calculated because a required component is unavailable.',
                422,
                'READINESS_COMPONENT_UNAVAILABLE',
                [
                    'practical_experience_available' => $practicalExperience['score'] !== null,
                    'assessment_reliability_available' => $assessmentReliability['score'] !== null,
                ],
            );
        }

        $weights = config('readiness.weights', []);
        $skillMatch = (float) $result['base_readiness_score'];
        $practicalExperienceScore = (float) $practicalExperience['score'];
        $assessmentReliabilityScore = (float) $assessmentReliability['score'];
        $profileCompletenessScore = (float) $profileCompleteness['score'];

        $finalScore =
            ($skillMatch * (float) ($weights['skill_match'] ?? 0.65))
            + ($practicalExperienceScore * (float) ($weights['practical_experience'] ?? 0.20))
            + ($assessmentReliabilityScore * (float) ($weights['assessment_reliability'] ?? 0.10))
            + ($profileCompletenessScore * (float) ($weights['profile_completeness'] ?? 0.05));

        $finalScore = min(max($finalScore, 0.0), 100.0);

        $criticalMinimumMatch = (float) config('readiness.critical_skill.minimum_match', 0.50);
        $criticalCap = (float) config('readiness.critical_skill.cap', 69.0);
        $criticalCapApplied = false;

        foreach ($result['skill_results'] as $skillResult) {
            if (! (bool) $skillResult['is_critical']) {
                continue;
            }

            $requiredLevel = (float) $skillResult['required_level'];
            $currentLevel = (float) $skillResult['current_level'];
            $match = $requiredLevel > 0 ? min(max($currentLevel / $requiredLevel, 0.0), 1.0) : 1.0;

            if ($match < $criticalMinimumMatch) {
                $criticalCapApplied = true;
                break;
            }
        }

        if ($criticalCapApplied) {
            $finalScore = min($finalScore, $criticalCap);
        }

        $finalScore = round($finalScore, 2);
        $band = $this->resolveBand($finalScore);
        // FIX: algorithm_version now reflects the FastAPI skill-gap algorithm
        // actually used for this calculation (already validated as a
        // non-empty string by validateDataScienceResult() above), not the
        // Laravel readiness formula version. The Laravel formula/weights
        // version is tracked separately as configuration_version.
        $algorithmVersion = (string) $result['algorithm_version'];
        $configurationVersion = (string) config('readiness.configuration_version', 'readiness-v1');
        $calculatedAt = now();

        $snapshot = [
            'career_role_id' => (int) $careerRole->id,
            'career_role_version' => (int) $careerRole->version,
            'student_profile_id' => (int) $studentProfile->id,
            'payload' => $payload,
            'fastapi_result' => $result,
            'components' => [
                'skill_match' => $skillMatch,
                'practical_experience' => $practicalExperience,
                'assessment_reliability' => $assessmentReliability,
                'profile_completeness' => $profileCompleteness,
            ],
            'formula' => [
                'weights' => $weights,
                'unrounded_score' => $finalScore,
            ],
            'critical_skill_rule' => [
                'minimum_match' => $criticalMinimumMatch,
                'cap' => $criticalCap,
                'applied' => $criticalCapApplied,
            ],
            'algorithm_version' => $algorithmVersion,
            'configuration_version' => $configurationVersion,
            'request_id' => $requestId,
            'calculated_at' => $calculatedAt->toIso8601String(),
        ];

        return DB::transaction(function () use (
            $studentProfile,
            $careerRole,
            $finalScore,
            $skillMatch,
            $practicalExperienceScore,
            $assessmentReliabilityScore,
            $profileCompletenessScore,
            $criticalCapApplied,
            $band,
            $algorithmVersion,
            $configurationVersion,
            $calculatedAt,
            $snapshot,
        ) {
            return ReadinessResult::create([
                'student_profile_id' => $studentProfile->id,
                'career_role_id' => $careerRole->id,
                'career_role_version' => $careerRole->version,
                'score' => $finalScore,
                'skill_match_component' => $skillMatch,
                'practical_experience_component' => $practicalExperienceScore,
                'assessment_reliability_component' => $assessmentReliabilityScore,
                'profile_completeness_component' => $profileCompletenessScore,
                'critical_cap_applied' => $criticalCapApplied,
                'band' => $band,
                'algorithm_version' => $algorithmVersion,
                'configuration_version' => $configurationVersion,
                'calculated_at' => $calculatedAt,
                'snapshot' => $snapshot,
            ]);
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

    private function resolveBand(float $score): string
    {
        foreach (config('readiness.bands', []) as $band => $range) {
            if ($score >= (float) $range['min'] && $score <= (float) $range['max']) {
                return $band;
            }
        }

        return 'highly_ready';
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
