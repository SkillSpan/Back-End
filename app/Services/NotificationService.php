<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationPreference;
use Illuminate\Database\Eloquent\Collection;

class NotificationService
{
    public function dispatch(
        int $userId,
        string $category,
        string $title,
        ?string $body = null,
        ?string $link = null,
        ?string $eventKey = null,
        string $channel = 'in_app',
        ?int $organizationId = null,
        ?string $entityType = null,
        ?int $entityId = null,
    ): ?Notification {
        if (! $this->isEnabled($userId, $category, $channel)) {
            return null;
        }

        if ($eventKey && Notification::where('user_id', $userId)
            ->where('event_key', $eventKey)
            ->exists()) {
            return null;
        }

        return Notification::create([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'category' => $category,
            'channel' => $channel,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'event_key' => $eventKey ?? $this->defaultEventKey($userId, $category),
        ]);
    }

    public function list(int $userId, array $filters = [], int $perPage = 20)
    {
        return Notification::where('user_id', $userId)
            ->when(
                ! empty($filters['category']),
                fn ($q) => $q->where('category', $filters['category'])
            )
            ->when(
                ! empty($filters['unread_only']),
                fn ($q) => $q->whereNull('read_at')
            )
            ->latest()
            ->paginate($perPage);
    }

    public function unreadCount(int $userId, ?string $category = null): int
    {
        return Notification::where('user_id', $userId)
            ->whereNull('read_at')
            ->when($category, fn ($q) => $q->where('category', $category))
            ->count();
    }

    public function markAsRead(int $userId, int $notificationId): ?Notification
    {
        $notification = Notification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->first();

        if (! $notification) {
            return null;
        }

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return $notification->fresh();
    }

    public function markAllAsRead(int $userId, ?string $category = null): int
    {
        return Notification::where('user_id', $userId)
            ->whereNull('read_at')
            ->when($category, fn ($q) => $q->where('category', $category))
            ->update(['read_at' => now()]);
    }

    public function isEnabled(int $userId, string $category, string $channel = 'in_app'): bool
    {
        $preference = NotificationPreference::where('user_id', $userId)
            ->where('category', $category)
            ->where('channel', $channel)
            ->first();

        return $preference?->enabled ?? true;
    }

    public function getPreferences(int $userId): Collection
    {
        return NotificationPreference::where('user_id', $userId)
            ->orderBy('category')
            ->get();
    }

    public function setPreference(
        int $userId,
        string $category,
        string $channel,
        bool $enabled,
    ): NotificationPreference {
        return NotificationPreference::updateOrCreate(
            ['user_id' => $userId, 'category' => $category, 'channel' => $channel],
            ['enabled' => $enabled],
        );
    }

    private function defaultEventKey(int $userId, string $category): string
    {
        return $category.':'.uniqid();
    }
}
