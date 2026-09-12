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
            'critical_skill_readiness_cap' => $fastApiResult['critical_skill_readiness_cap'] ?? null,
            'critical_skill_gap_count' => $fastApiResult['critical_skill_gap_count'] ?? 0,
            'critical_skill_names' => $fastApiResult['critical_skill_names'] ?? [],
            'band' => $this->band,
            'algorithm_version' => $this->algorithm_version,
            'configuration_version' => $this->configuration_version,
            'calculated_at' => optional($this->calculated_at)->toIso8601String(),
            'total_skills' => $fastApiResult['total_skills'] ?? null,
            'met_skills' => $fastApiResult['met_skills'] ?? null,
            'skills_with_gap' => $fastApiResult['skills_with_gap'] ?? null,
            'skill_results' => $fastApiResult['skill_results'] ?? [],
        ];
    }
}
