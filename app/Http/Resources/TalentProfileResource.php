<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TalentProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'name' => $this['name'] ?? null,
            'career_role' => $this['career_role'] ?? null,
            'specialization' => $this['specialization'] ?? null,
            'university_name' => $this['university_name'] ?? null,
            'career_status' => $this['career_status'] ?? null,
            'enrollment_status' => $this['enrollment_status'] ?? null,
            'availability' => $this['availability'] ?? null,
            'preferred_work_type' => $this['preferred_work_type'] ?? null,
            'completeness_percent' => $this['completeness_percent'] ?? 0,
            'visibility' => $this['visibility'] ?? null,
            'contactable' => $this['contactable'] ?? false,
            'readiness' => [
                'score' => $this['readiness']['score'] ?? null,
                'band' => $this['readiness']['band'] ?? null,
            ],

            'bio' => $this['bio'] ?? null,
            'interests' => $this['interests'] ?? [],
            'academic_level' => $this['academic_level'] ?? null,
            'expected_graduation' => $this['expected_graduation'] ?? null,
            'graduation_status' => $this['graduation_status'] ?? false,

            'skills' => $this['skills'] ?? [],

            'projects' => $this['projects'] ?? [],

            'evaluation' => [
                'authorized' => $this['evaluation']['authorized'] ?? false,
                'overall_rating' => $this['evaluation']['overall_rating'] ?? null,
                'criteria_scores' => $this['evaluation']['criteria_scores'] ?? [],
                'evaluations_count' => $this['evaluation']['evaluations_count'] ?? 0,
                'reviewed_at' => $this['evaluation']['reviewed_at'] ?? null,
            ],
        ];
    }
}
