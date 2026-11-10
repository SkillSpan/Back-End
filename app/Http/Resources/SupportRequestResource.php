<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'reason' => $this->reason,
            'subject' => $this->subject,
            'transcript' => $this->transcript ?? [],
            'assigned_to' => $this->assigned_to,
            'assignee_name' => $this->whenLoaded('assignee', fn () => $this->assignee?->name),
            'source_interaction_id' => $this->source_interaction_id,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'user_email' => $this->whenLoaded('user', fn () => $this->user?->email),

            'has_unread' => $this->when(
                $this->unread_count !== null,
                fn () => (int) $this->unread_count > 0,
            ),

            'handoff_seconds' => (int) config('services.support.handoff_seconds', 5),

            'messages' => SupportMessageResource::collection(
                $this->whenLoaded('messages')
            ),
        ];
    }
}
