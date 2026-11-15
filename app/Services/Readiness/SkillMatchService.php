<?php

namespace App\Services\Readiness;

use App\Exceptions\SkillMatchException;

class SkillMatchService
{
    private const ALGORITHM_VERSION = 'skill-match-v1';

    private const WEIGHT_CONFIGURATION_VERSION = 'career-role-config-v1';

    public function calculate(array $payload): array
    {
        $skills = $payload['skills'];

        $this->assertNoDuplicateSkillIds($skills);

        $originalWeightTotal = array_sum(array_column($skills, 'importance_weight'));

        if ($originalWeightTotal <= 0) {
            throw new SkillMatchException(
                'Skill Match input must have a positive total importance weight.',
                'INVALID_WEIGHT_TOTAL',
            );
        }

        $weightedAchievedTotal = 0.0;
        $weightedRequiredTotal = 0.0;
        $normalizedWeightTotal = 0.0;

        $metCount = 0;
        $partialCount = 0;
        $notRequiredCount = 0;

        $skillResults = [];

        foreach ($skills as $skill) {
            $currentLevel = (float) $skill['current_level'];
            $requiredLevel = (float) $skill['required_level'];
            $importanceWeight = (float) $skill['importance_weight'];

            $normalizedWeight = $importanceWeight / $originalWeightTotal;
            $normalizedWeightTotal += $normalizedWeight;

            $achievedLevel = min($currentLevel, $requiredLevel);

            if ($requiredLevel <= 0) {
                $status = 'not_required';
                $matchRatio = 1.0;
                $notRequiredCount++;
            } elseif ($currentLevel >= $requiredLevel) {
                $status = 'met';
                $matchRatio = 1.0;
                $metCount++;
            } else {
                $status = 'partial';
                $matchRatio = round($achievedLevel / $requiredLevel, 4);
                $partialCount++;
            }

            $weightedAchievedTotal += $achievedLevel * $normalizedWeight;
            $weightedRequiredTotal += $requiredLevel * $normalizedWeight;

            $skillResults[] = [
                'skill_id' => (int) $skill['skill_id'],
                'skill_name' => (string) $skill['skill_name'],
                'current_level' => $currentLevel,
                'required_level' => $requiredLevel,
                'importance_weight' => $importanceWeight,
                'normalized_importance_weight' => round($normalizedWeight, 6),
                'achieved_level' => $achievedLevel,
                'match_ratio' => $matchRatio,
                'status' => $status,
            ];
        }

        $skillMatchScore = $weightedRequiredTotal > 0
            ? min(round(($weightedAchievedTotal / $weightedRequiredTotal) * 100, 2), 100.0)
            : 100.0;

        return [
            'student_profile_id' => (int) $payload['student_profile_id'],
            'career_role_id' => (int) $payload['career_role_id'],
            'career_role_version' => (int) $payload['career_role_version'],
            'user_id' => (int) $payload['user_id'],
            'target_role' => (string) $payload['target_role'],
            'algorithm_version' => self::ALGORITHM_VERSION,
            'weight_configuration_version' => self::WEIGHT_CONFIGURATION_VERSION,
            'original_weight_total' => round($originalWeightTotal, 6),
            'normalized_weight_total' => round($normalizedWeightTotal, 6),
            'weighted_achieved_total' => round($weightedAchievedTotal, 4),
            'weighted_required_total' => round($weightedRequiredTotal, 4),
            'skill_match_score' => $skillMatchScore,
            'total_skills' => count($skills),
            'met_skills' => $metCount,
            'partial_skills' => $partialCount,
            'not_required_skills' => $notRequiredCount,
            'skill_results' => $skillResults,
        ];
    }

    private function assertNoDuplicateSkillIds(array $skills): void
    {
        $seen = [];

        foreach ($skills as $skill) {
            $skillId = (int) $skill['skill_id'];

            if (isset($seen[$skillId])) {
                throw new SkillMatchException(
                    'Skill Match input must not contain duplicate skill_id values.',
                    'DUPLICATE_SKILL_ID',
                );
            }

            $seen[$skillId] = true;
        }
    }
}
