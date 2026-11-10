<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TalentCardResource extends JsonResource
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
            'top_skills' => $this['top_skills'] ?? [],
        ];
    }
}
