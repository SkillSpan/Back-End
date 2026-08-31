<?php

namespace App\Services\Readiness;

use App\Exceptions\ReadinessException;
use App\Models\CareerRole;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;

class ReadinessPayloadBuilder
{
    /**
     * Build the exact request contract accepted by the current FastAPI service.
     * importance_weight is normalized on a 0..1 scale.
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
                'skill_id' => (int) $roleSkill->skill_id,
                'skill_name' => (string) $roleSkill->skill->name,
                'current_level' => $currentLevel,
                'required_level' => $requiredLevel,
                // importance_weight is normalized on a 0..1 scale.
                'importance_weight' => $importanceWeight,
                'is_critical' => (bool) $roleSkill->is_critical,
            ];
        }

        return [
            'student_profile_id' => (int) $studentProfile->id,
            'career_role_id' => (int) $careerRole->id,
            'career_role_version' => (int) $careerRole->version,
            'user_id' => (int) $studentProfile->user_id,
            'target_role' => (string) $careerRole->title,
            'skills' => $skills,
        ];
    }
}
