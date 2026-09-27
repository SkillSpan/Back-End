<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mentor_student_connection_id' => $this->mentor_student_connection_id,
            'status' => $this->status,
            'created_by' => $this->created_by,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'retention_expires_at' => $this->retention_expires_at?->toIso8601String(),
            'connection' => $this->whenLoaded('connection', fn () => [
                'id' => $this->connection?->id,
                'mentor_id' => $this->connection?->mentor_id,
                'student_id' => $this->connection?->student_id,
                'project_id' => $this->connection?->project_id,
                'status' => $this->connection?->status,
                'mentor' => $this->whenLoaded('connection.mentor', fn () => [
                    'id' => $this->connection?->mentor?->id,
                    'name' => $this->connection?->mentor?->name,
                ]),
                'student' => $this->whenLoaded('connection.student', fn () => [
                    'id' => $this->connection?->student?->id,
                    'name' => $this->connection?->student?->name,
                ]),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
