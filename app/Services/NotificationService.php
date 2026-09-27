<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationPreference;
use Illuminate\Database\Eloquent\Collection;

/**
 * Central notification gateway for the mentor-student communication
 * system. Every in-app notification goes through dispatch() so that
 * per-user, per-category, per-channel preferences are honoured in one
 * place instead of being re-implemented at each call site.
 *
 * Design notes:
 * - Preferences are opt-OUT: absence of a row means "enabled". A row with
 *   enabled=false suppresses that category/channel for that user.
 * - event_key is unique per user (DB constraint), so dispatch() is
 *   idempotent: re-dispatching the same event does not create a duplicate.
 * - Suppressed notifications are still counted so callers can report how
 *   many were skipped, without throwing.
 */
class NotificationService
{
    /**
     * Dispatch an in-app notification, honouring the recipient's
     * preferences. Returns the created Notification, or null when the
     * recipient has disabled this category/channel or the event was
     * already dispatched.
     */
    public function dispatch(
        int $userId,
        string $category,
        string $title,
        ?string $body = null,
        ?string $link = null,
        ?string $eventKey = null,
        string $channel = 'in_app',
    ): ?Notification {
        if (! $this->isEnabled($userId, $category, $channel)) {
            return null;
        }

        // Idempotency: the unique [user_id, event_key] constraint would
        // otherwise throw on a re-dispatch of the same logical event.
        if ($eventKey && Notification::where('user_id', $userId)
            ->where('event_key', $eventKey)
            ->exists()) {
            return null;
        }

        return Notification::create([
            'user_id' => $userId,
            'category' => $category,
            'channel' => $channel,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'event_key' => $eventKey ?? $this->defaultEventKey($userId, $category),
        ]);
    }

    /**
     * List a user's notifications, newest first.
     *
     * @param  array{category?: string, unread_only?: bool}  $filters
     */
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

    /**
     * Count a user's unread notifications, optionally per category.
     */
    public function unreadCount(int $userId, ?string $category = null): int
    {
        return Notification::where('user_id', $userId)
            ->whereNull('read_at')
            ->when($category, fn ($q) => $q->where('category', $category))
            ->count();
    }

    /**
     * Mark a single notification as read, scoped to its owner so a user
     * cannot flip another user's notification.
     */
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

    /**
     * Mark every unread notification for a user as read. Returns the
     * number of rows affected.
     */
    public function markAllAsRead(int $userId, ?string $category = null): int
    {
        return Notification::where('user_id', $userId)
            ->whereNull('read_at')
            ->when($category, fn ($q) => $q->where('category', $category))
            ->update(['read_at' => now()]);
    }

    /**
     * Whether a category/channel is enabled for a user. Opt-out: no row
     * means enabled.
     */
    public function isEnabled(int $userId, string $category, string $channel = 'in_app'): bool
    {
        $preference = NotificationPreference::where('user_id', $userId)
            ->where('category', $category)
            ->where('channel', $channel)
            ->first();

        return $preference?->enabled ?? true;
    }

    /**
     * All explicit preferences for a user (rows only, not defaults).
     */
    public function getPreferences(int $userId): Collection
    {
        return NotificationPreference::where('user_id', $userId)
            ->orderBy('category')
            ->get();
    }

    /**
     * Upsert a preference for a user.
     */
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

    /**
     * Fallback event key when the caller does not supply one. Includes a
     * random component so two distinct events in the same second do not
     * collide on the unique index.
     */
    private function defaultEventKey(int $userId, string $category): string
    {
        return $category.':'.uniqid();
    }
}
