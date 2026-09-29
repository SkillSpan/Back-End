<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mentor_id' => $this->mentor_id,
            'student_id' => $this->student_id,
            'project_id' => $this->project_id,
            'status' => $this->status,
            'initiated_by' => $this->initiated_by,
            'disconnected_reason' => $this->disconnected_reason,
            'disconnected_at' => $this->disconnected_at?->toIso8601String(),
            'mentor' => [
                'id' => $this->whenLoaded('mentor')->id ?? null,
                'name' => $this->whenLoaded('mentor')->name ?? null,
                'email' => $this->whenLoaded('mentor')->email ?? null,
            ],
            'student' => [
                'id' => $this->whenLoaded('student')->id ?? null,
                'name' => $this->whenLoaded('student')->name ?? null,
                'email' => $this->whenLoaded('student')->email ?? null,
            ],
            'student_profile' => $this->whenLoaded(
                'student.studentProfile',
                fn () => [
                    'university_name' => $this->student->studentProfile?->university_name,
                    'specialization' => $this->student->studentProfile?->specialization,
                    'career_status' => $this->student->studentProfile?->career_status,
                ],
            ),
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project?->id,
                'title' => $this->project?->title,
                'status' => $this->project?->status,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
