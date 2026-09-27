<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_id' => $this->sender_id,
            'sender_name' => $this->whenLoaded('sender', fn () => $this->sender?->name),
            'body' => $this->body,
            'message_type' => $this->message_type,
            'metadata' => $this->metadata,
            'read_at' => $this->read_at?->toIso8601String(),
            'read_by' => $this->read_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
