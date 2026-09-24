<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReadinessResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $snapshot = is_array($this->snapshot) ? $this->snapshot : [];
        $fastApiResult = is_array($snapshot['fastapi_result'] ?? null) ? $snapshot['fastapi_result'] : [];

        /*
         * The critical-skill cap is Laravel's decision, not the service's.
         *
         * Skill Match v1 returns per-skill `is_critical` and `match_ratio` and
         * no cap metadata at all, so ReadinessService applies the cap itself
         * and records the outcome under `critical_skill_rule`.
         *
         * These fields used to be read from `fastapi_result` -- the service
         * response -- where they can never exist. Every capped learner was
         * therefore told a cap had been applied while the cap value and the
         * offending skills came back as null/0/[]. That made the decision
         * impossible to explain and impossible to dispute (§12.6 / REC-08).
         */
        $criticalRule = is_array($snapshot['critical_skill_rule'] ?? null)
            ? $snapshot['critical_skill_rule']
            : [];

        $criticalSkillNames = is_array($criticalRule['critical_skill_names'] ?? null)
            ? array_values($criticalRule['critical_skill_names'])
            : [];

        return [
            'id' => $this->id,
            'student_profile_id' => $this->student_profile_id,
            'career_role_id' => $this->career_role_id,
            'career_role_version' => $this->career_role_version,
            'decision_id' => $this->decisionSnapshot?->decision_uuid,
            'score' => (float) $this->score,
            'skill_match_component' => $this->skill_match_component === null ? null : (float) $this->skill_match_component,
            'practical_experience_component' => $this->practical_experience_component === null ? null : (float) $this->practical_experience_component,
            'assessment_reliability_component' => $this->assessment_reliability_component === null ? null : (float) $this->assessment_reliability_component,
            'profile_completeness_component' => $this->profile_completeness_component === null ? null : (float) $this->profile_completeness_component,
            'critical_cap_applied' => (bool) $this->critical_cap_applied,
            'is_provisional' => (bool) $this->is_provisional,
            'critical_skill_readiness_cap' => isset($criticalRule['cap']) ? (float) $criticalRule['cap'] : null,
            // Counted from the same array, so it can never disagree with the names.
            'critical_skill_gap_count' => count($criticalSkillNames),
            'critical_skill_names' => $criticalSkillNames,
            'band' => $this->band,
            'algorithm_version' => $this->algorithm_version,
            'configuration_version' => $this->configuration_version,
            'calculated_at' => optional($this->calculated_at)->toIso8601String(),
            'total_skills' => $fastApiResult['total_skills'] ?? null,
            'met_skills' => $fastApiResult['met_skills'] ?? null,
            /*
             * Skill Match v1 partitions skills into met / partial /
             * not-required, so a skill "with a gap" is a partial one. The
             * response carries no `skills_with_gap` key, so reading it here
             * returned null for every learner.
             */
            'skills_with_gap' => $fastApiResult['partial_skills'] ?? null,
            'skill_results' => $fastApiResult['skill_results'] ?? [],
        ];
    }
}
