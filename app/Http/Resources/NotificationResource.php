<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Presentation for a single in-app notification. `is_read` is derived
 * from `read_at` so the client does not have to interpret a timestamp
 * to render the unread badge.
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'channel' => $this->channel,
            'title' => $this->title,
            'body' => $this->body,
            'link' => $this->link,
            'event_key' => $this->event_key,
            'is_read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
