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
use Illuminate\Support\Str;

class ReadinessService
{
    public function __construct(
        private readonly DataScienceClient $dataScienceClient,
        private readonly ReadinessPayloadBuilder $payloadBuilder,
    ) {
    }

    public function calculate(StudentProfile $studentProfile, ?int $careerRoleId, string $requestId): ReadinessResult
    {
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
            $exists = CareerRole::query()->whereKey($careerRoleId)->exists();

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

        $missingSkillIds = [];

        foreach ($roleSkills as $roleSkill) {
            $latestEvaluation = SkillEvaluation::query()
                ->where('student_profile_id', $studentProfile->id)
                ->where('skill_id', $roleSkill->skill_id)
                ->orderByDesc('calculated_at')
                ->orderByDesc('id')
                ->first();

            $roleSkill->setAttribute('latest_evaluation', $latestEvaluation);

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

        $payload = $this->payloadBuilder->build($studentProfile, $careerRole, $roleSkills);
        $result = $this->dataScienceClient->analyze($payload, $requestId);
        $this->validateDataScienceResult($result, $payload, $careerRole, $roleSkills);

        $algorithmVersion = (string) config('services.data_science.algorithm_version', 'skill-gap-v1');
        $calculatedAt = now();

        $snapshot = [
            'career_role_id' => (int) $careerRole->id,
            'career_role_version' => (int) $careerRole->version,
            'student_profile_id' => (int) $studentProfile->id,
            'user_id' => (int) $studentProfile->user_id,
            'payload' => $payload,
            'fastapi_result' => $result,
            'algorithm_version' => $algorithmVersion,
            'request_id' => $requestId,
            'calculated_at' => $calculatedAt->toIso8601String(),
        ];

        return DB::transaction(function () use (
            $studentProfile,
            $careerRole,
            $result,
            $algorithmVersion,
            $calculatedAt,
            $snapshot,
        ) {
            return ReadinessResult::create([
                'student_profile_id' => $studentProfile->id,
                'career_role_id' => $careerRole->id,
                'career_role_version' => $careerRole->version,
                'score' => $result['readiness_score'],
                'skill_match_component' => $result['base_readiness_score'],
                'practical_experience_component' => null,
                'assessment_reliability_component' => null,
                'profile_completeness_component' => null,
                'critical_cap_applied' => $result['critical_skill_cap_applied'],
                'band' => null,
                'algorithm_version' => $algorithmVersion,
                'calculated_at' => $calculatedAt,
                'snapshot' => $snapshot,
            ]);
        });
    }

    public function latest(StudentProfile $studentProfile, ?int $careerRoleId = null): ?ReadinessResult
    {
        return ReadinessResult::query()
            ->where('student_profile_id', $studentProfile->id)
            ->when($careerRoleId, fn ($query) => $query->where('career_role_id', $careerRoleId))
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
            'user_id',
            'target_role',
            'base_readiness_score',
            'readiness_score',
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

        if ((int) $result['user_id'] !== (int) $payload['user_id']) {
            throw new ReadinessIntegrationException(
                'The Data Science response does not match the authenticated user.',
                502,
                'DATA_SCIENCE_RESPONSE_MISMATCH',
            );
        }

        if ((string) $result['target_role'] !== (string) $careerRole->title) {
            throw new ReadinessIntegrationException(
                'The Data Science response does not match the requested career role.',
                502,
                'DATA_SCIENCE_RESPONSE_MISMATCH',
            );
        }

        foreach (['base_readiness_score', 'readiness_score'] as $field) {
            if (! is_numeric($result[$field]) || (float) $result[$field] < 0 || (float) $result[$field] > 100) {
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

        if (count($result['skill_results']) !== $roleSkills->count()) {
            throw new ReadinessIntegrationException(
                'The Data Science response contains an unexpected number of skill results.',
                502,
                'DATA_SCIENCE_INVALID_RESPONSE',
            );
        }

        $allowedSkillNames = $roleSkills->pluck('skill.name')->filter()->values()->all();
        $skillResultNames = [];

        foreach ($result['skill_results'] as $skillResult) {
            foreach (['skill_name', 'current_level', 'required_level', 'importance_weight', 'is_critical', 'gap', 'status'] as $field) {
                if (! array_key_exists($field, $skillResult)) {
                    throw new ReadinessIntegrationException(
                        'The Data Science response contains an incomplete skill result.',
                        502,
                        'DATA_SCIENCE_INVALID_RESPONSE',
                        ['missing_field' => $field],
                    );
                }
            }

            $skillName = (string) $skillResult['skill_name'];
            if (! in_array($skillName, $allowedSkillNames, true)) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains an unknown skill.',
                    502,
                    'DATA_SCIENCE_INVALID_RESPONSE',
                    ['skill_name' => $skillName],
                );
            }

            $skillResultNames[] = $skillName;
        }

        if (count(array_unique($skillResultNames)) !== count($skillResultNames)) {
            throw new ReadinessIntegrationException(
                'The Data Science response contains duplicate skill results.',
                502,
                'DATA_SCIENCE_INVALID_RESPONSE',
            );
        }

        Log::debug('Validated Data Science readiness response.', [
            'career_role_id' => $careerRole->id,
            'career_role_version' => $careerRole->version,
            'result_skill_count' => count($result['skill_results']),
        ]);
    }
}
