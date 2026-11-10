<?php

namespace App\Services\Intelligence;

use App\Exceptions\IntelligenceException;
use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;

class IntelligencePayloadBuilder
{
    public function build(
        StudentProfile $studentProfile,
        CareerRole $careerRole,
        Collection $roleSkills,
        Collection $evaluations,
        array $evidenceSummary,
        string $algorithmVersion,
        string $configurationVersion,
    ): array {
        $latestEvaluationsBySkillId = $evaluations
            ->sortByDesc('calculated_at')
            ->sortByDesc('id')
            ->groupBy(fn (SkillEvaluation $evaluation) => (int) $evaluation->skill_id);

        $skills = [];

        foreach ($roleSkills as $roleSkill) {
            $skillId = (int) $roleSkill->skill_id;
            $skillName = (string) $roleSkill->skill?->name;

            $evaluation = $latestEvaluationsBySkillId->get($skillId)?->first();

            if ($evaluation === null) {
                throw new IntelligenceException(
                    'The learner does not have evaluations for all required skills.',
                    422,
                    'INTELLIGENCE_VALIDATION_ERROR',
                    ['missing_skill_id' => $skillId],
                );
            }

            $requiredLevel = (float) $roleSkill->required_level;
            $currentLevel = (float) $evaluation->level;
            $confidence = (float) $evaluation->confidence;

            $this->assertLevelInRange($currentLevel, 'current_level');
            $this->assertLevelInRange($requiredLevel, 'required_level');

            $importanceWeight = (float) $roleSkill->importance_weight;

            if ($importanceWeight <= 0 || $importanceWeight > 1) {
                throw new IntelligenceException(
                    'A career-role skill has an invalid importance weight. Allowed range: (0, 1].',
                    422,
                    'INTELLIGENCE_VALIDATION_ERROR',
                    ['skill_id' => $skillId],
                );
            }

            $skills[] = [
                'skill_id' => $skillId,
                'skill_name' => $skillName,
                'current_level' => $currentLevel,
                'required_level' => $requiredLevel,
                'importance_weight' => $importanceWeight,
                'is_critical' => (bool) $roleSkill->is_critical,
                'confidence' => $confidence,
                'evidence' => $evidenceSummary[$skillId] ?? [],
                'prerequisite_skill_ids' => $this->prerequisiteSkillIds($roleSkill),
            ];
        }

        return [
            'learner' => [
                'student_profile_id' => (int) $studentProfile->id,
                'user_id' => (int) $studentProfile->user_id,
                'availability' => $studentProfile->availability,
                'weekly_availability_hours' => $studentProfile->weekly_availability_hours !== null
                    ? (float) $studentProfile->weekly_availability_hours
                    : null,
            ],
            'role' => [
                'id' => (int) $careerRole->id,
                'title' => (string) $careerRole->title,
                'version' => (int) $careerRole->version,
            ],
            'skills' => $skills,
            'algorithm_version' => $algorithmVersion,
            'configuration_version' => $configurationVersion,
        ];
    }

    private function prerequisiteSkillIds(CareerRoleSkill $roleSkill): array
    {
        $ids = $roleSkill->prerequisites
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === [] && $roleSkill->prerequisite_skill_id !== null) {
            $ids = [(int) $roleSkill->prerequisite_skill_id];
        }

        return $ids;
    }

    private function assertLevelInRange(float $level, string $field): void
    {
        if ($level < 0 || $level > 5) {
            throw new IntelligenceException(
                "A skill {$field} is outside the allowed 0..5 scale.",
                422,
                'INTELLIGENCE_VALIDATION_ERROR',
                ['field' => $field, 'value' => $level],
            );
        }
    }
}
