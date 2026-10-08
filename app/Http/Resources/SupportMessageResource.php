<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One message in a support thread.
 *
 * `sender_id` is exposed so the client can tell its own messages from the other
 * side's by comparing it with the authenticated user's id — the resource is
 * built without knowledge of who is reading it, and guessing would be wrong the
 * moment an administrator reads the same thread the learner sees.
 *
 * `message_type: system` marks the handoff notice. It is rendered like any other
 * bubble but must not be attributed to a person.
 */
class SupportMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'support_request_id' => $this->support_request_id,
            'sender_id' => $this->sender_id,
            'sender_name' => $this->whenLoaded('sender', fn () => $this->sender?->name),
            'body' => $this->body,
            'message_type' => $this->message_type,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
