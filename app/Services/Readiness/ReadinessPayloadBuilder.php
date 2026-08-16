<?php

namespace App\Services\Readiness;

use App\Models\CareerRole;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;
use App\Exceptions\ReadinessException;

class ReadinessPayloadBuilder
{
    /**
     * Build the exact request contract accepted by the current FastAPI service.
     * FastAPI accepts importance weights on a 0..100 relative scale, while the
     * Laravel database/SRS stores normalized weights on a 0..1 scale.
     */
    public function build(StudentProfile $studentProfile, CareerRole $careerRole, Collection $roleSkills): array
    {
        $skills = [];

        foreach ($roleSkills as $roleSkill) {
            $requiredLevel = (float) $roleSkill->required_level;
            $importanceWeight = (float) $roleSkill->importance_weight;

            if ($requiredLevel < 0 || $requiredLevel > 5) {
                throw new ReadinessException(
                    'A career-role skill has an invalid required level. Allowed range: 0..5.',
                    422,
                    'INVALID_REQUIRED_LEVEL',
                );
            }

            if ($importanceWeight <= 0 || $importanceWeight > 1) {
                throw new ReadinessException(
                    'Career-role importance weights must be greater than 0 and at most 1.',
                    422,
                    'INVALID_IMPORTANCE_WEIGHT',
                );
            }

            $evaluation = $roleSkill->latest_evaluation;

            if ($evaluation === null) {
                continue;
            }

            $currentLevel = (float) $evaluation->level;

            if ($currentLevel < 0 || $currentLevel > 5) {
                throw new ReadinessException(
                    'A skill evaluation contains an invalid level. Allowed range: 0..5.',
                    422,
                    'INVALID_SKILL_LEVEL',
                );
            }

            $skills[] = [
                'skill_name' => (string) $roleSkill->skill->name,
                'current_level' => $currentLevel,
                'required_level' => $requiredLevel,
                // FastAPI currently accepts relative weights up to 100.
                // Multiplying all normalized weights by 100 keeps the ratio unchanged.
                'importance_weight' => round($importanceWeight * 100, 3),
                'is_critical' => (bool) $roleSkill->is_critical,
            ];
        }

        return [
            'user_id' => (int) $studentProfile->user_id,
            'target_role' => (string) $careerRole->title,
            'skills' => $skills,
        ];
    }
}
