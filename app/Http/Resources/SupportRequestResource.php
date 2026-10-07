<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An escalated assistant conversation.
 *
 * `transcript` is the snapshot taken at handoff time — it is a historical
 * record, not a live view of the thread. Live replies arrive as
 * `SupportMessageResource` under `messages`. The two are deliberately separate
 * so the UI can show "what the assistant and learner had said" distinctly from
 * "what support has said since".
 *
 * `handoff_seconds` is a config passthrough rather than a property of the
 * request. It is here because the client needs it at exactly the moment it
 * receives this payload, and a second round trip to fetch one integer would be
 * worse than the small impurity.
 */
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

            // Who the thread belongs to. `user_id` is always present because it
            // is what the client compares a message's `sender_id` against to
            // tell the two sides apart; the name and email appear only when the
            // `user` relation was loaded, which the learner-facing endpoints
            // deliberately do not do — a learner does not need their own name
            // echoed back, and a support agent does.
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'user_email' => $this->whenLoaded('user', fn () => $this->user?->email),

            // Inbox-only: true when the thread holds a message the viewer has
            // not opened. Absent unless the caller asked for the count, so the
            // learner endpoints are unaffected.
            'has_unread' => $this->when(
                $this->unread_count !== null,
                fn () => (int) $this->unread_count > 0,
            ),

            // How long the client should count down before revealing the thread.
            'handoff_seconds' => (int) config('services.support.handoff_seconds', 5),

            'messages' => SupportMessageResource::collection(
                $this->whenLoaded('messages')
            ),
        ];
    }
}
