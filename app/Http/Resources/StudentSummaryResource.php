<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'name' => $this['name'],
            'email' => $this['email'],
            'university_name' => $this['university_name'] ?? null,
            'specialization' => $this['specialization'] ?? null,
            'career_status' => $this['career_status'] ?? null,
            'interests' => $this['interests'] ?? null,
            'availability' => $this['availability'] ?? null,
            'preferred_work_type' => $this['preferred_work_type'] ?? null,
            'visibility' => $this['visibility'] ?? null,
            'completeness_percent' => $this['completeness_percent'] ?? 0,
            'enrollment_status' => $this['enrollment_status'] ?? null,
        ];
    }
}
