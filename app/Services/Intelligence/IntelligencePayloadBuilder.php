<?php

namespace App\Services\Intelligence;

use App\Exceptions\IntelligenceException;
use App\Models\CareerRole;
use App\Models\LearnerSkill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;

/**
 * US-INT-01 §12 — single reusable payload builder for skill-gap,
 * readiness and roadmap requests. No duplicated payload logic.
 *
 * Carries only validated decision data: learner reference + skill
 * state, approved role version + required skills (with importance,
 * criticality, prerequisites), availability, and the resolved
 * algorithm/configuration versions. Never PII, never credentials.
 */
class IntelligencePayloadBuilder
{
    /**
     * @param  Collection<int, LearnerSkill>  $learnerSkills
     * @param  Collection<int, SkillEvaluation>  $evaluations
     * @param  array<string, mixed>  $evidenceSummary
     */
    public function build(
        StudentProfile $studentProfile,
        CareerRole $careerRole,
        Collection $roleSkills,
        Collection $learnerSkills,
        Collection $evaluations,
        array $evidenceSummary,
        string $algorithmVersion,
        string $configurationVersion,
    ): array {
        $latestEvaluationsBySkillId = $evaluations
            ->sortByDesc('calculated_at')
            ->sortByDesc('id')
            ->groupBy(fn (SkillEvaluation $evaluation) => (int) $evaluation->skill_id);

        $learnerSkillsBySkillId = $learnerSkills
            ->keyBy(fn (LearnerSkill $learnerSkill) => (int) $learnerSkill->skill_id);

        $skills = [];

        foreach ($roleSkills as $roleSkill) {
            $skillId = (int) $roleSkill->skill_id;
            $skillName = (string) $roleSkill->skill?->name;

            $evaluation = $latestEvaluationsBySkillId->get($skillId)?->first();
            $learnerSkill = $learnerSkillsBySkillId->get($skillId);

            $requiredLevel = (float) $roleSkill->required_level;
            $currentLevel = $evaluation !== null ? (float) $evaluation->level : (float) ($learnerSkill?->level ?? 0);
            $confidence = $evaluation !== null
                ? (float) $evaluation->confidence
                : (float) ($learnerSkill?->confidence_score ?? 0);

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
                'prerequisite_skill_ids' => $roleSkill->prerequisite_skill_id !== null
                    ? [(int) $roleSkill->prerequisite_skill_id]
                    : [],
            ];
        }

        return [
            'learner' => [
                'student_profile_id' => (int) $studentProfile->id,
                'user_id' => (int) $studentProfile->user_id,
                'availability' => $studentProfile->availability,
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
