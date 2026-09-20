<?php

namespace App\Services\Readiness;

use App\Exceptions\SkillMatchException;

/**
 * Implements the skill-match-v1 algorithm exactly as specified by Data
 * Science (see conversation with the analyst, skill-match-v1 /
 * career-role-config-v1 contract). Deterministic: no DB access, no current
 * date, no randomness, no external calls — pure function of its input.
 */
class SkillMatchService
{
    private const ALGORITHM_VERSION = 'skill-match-v1';

    private const WEIGHT_CONFIGURATION_VERSION = 'career-role-config-v1';

    /**
     * @param  array{
     *     student_profile_id: int,
     *     career_role_id: int,
     *     career_role_version: int,
     *     user_id: int,
     *     target_role: string,
     *     skills: array<int, array{
     *         skill_id: int,
     *         skill_name: string,
     *         current_level: float,
     *         required_level: float,
     *         importance_weight: float,
     *         is_critical: bool,
     *     }>,
     * }  $payload
     */
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

            // Overachieving a skill does not push the score past 100% —
            // a skill's contribution is capped at what was required of it.
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

        // Edge case: every skill has required_level = 0 (nothing required
        // of the learner for this role) — nothing to be short of, so the
        // learner is fully matched by definition.
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
