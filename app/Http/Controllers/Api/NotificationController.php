<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNotificationPreferenceRequest;
use App\Http\Resources\NotificationResource;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Relevant-notifications API for the authenticated user. Every endpoint is
 * scoped to the caller's own notifications: a user can only read or mark
 * their own rows, which NotificationService enforces by filtering on
 * user_id rather than trusting the route parameter alone.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * GET /api/v1/notifications
     * List the caller's notifications. Optional ?category= and ?unread_only=1.
     */
    public function index(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $notifications = $this->notifications->list(
            userId: $request->user()->id,
            filters: [
                'category' => $request->query('category'),
                'unread_only' => $request->boolean('unread_only'),
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Notifications retrieved successfully.',
            'data' => NotificationResource::collection($notifications->items()),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'unread_count' => $this->notifications->unreadCount($request->user()->id),
            ],
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * GET /api/v1/notifications/unread-count
     * Cheap poll for the unread badge.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $count = $this->notifications->unreadCount(
            $request->user()->id,
            $request->query('category'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Unread count retrieved successfully.',
            'data' => ['unread_count' => $count],
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * POST /api/v1/notifications/{notification}/read
     * Mark one of the caller's notifications as read.
     */
    public function markAsRead(Request $request, int $notification): JsonResponse
    {
        $requestId = $this->requestId($request);

        $updated = $this->notifications->markAsRead($request->user()->id, $notification);

        if (! $updated) {
            // Same shape as the other 404s in this codebase — the row either
            // does not exist or belongs to someone else, and the caller is
            // not told which.
            return response()->json([
                'code' => 'NOTIFICATION_NOT_FOUND',
                'message' => 'The specified notification was not found.',
                'request_id' => $requestId,
            ], 404, ['X-Request-ID' => $requestId]);
        }

        return (new NotificationResource($updated))
            ->response()
            ->header('X-Request-ID', $requestId);
    }

    /**
     * POST /api/v1/notifications/read-all
     * Mark every unread notification as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $count = $this->notifications->markAllAsRead(
            $request->user()->id,
            $request->input('category'),
        );

        return response()->json([
            'success' => true,
            'message' => "{$count} notification(s) marked as read.",
            'data' => ['marked_read' => $count],
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * GET /api/v1/notifications/preferences
     * The caller's explicit preference rows.
     */
    public function preferences(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $preferences = $this->notifications->getPreferences($request->user()->id)
            ->map(fn ($p) => [
                'category' => $p->category,
                'channel' => $p->channel,
                'enabled' => $p->enabled,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Notification preferences retrieved successfully.',
            'data' => $preferences,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * PUT /api/v1/notifications/preferences
     * Upsert one preference row.
     */
    public function updatePreference(UpdateNotificationPreferenceRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $preference = $this->notifications->setPreference(
            userId: $request->user()->id,
            category: $request->input('category'),
            channel: $request->input('channel'),
            enabled: $request->boolean('enabled'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Notification preference updated successfully.',
            'data' => [
                'category' => $preference->category,
                'channel' => $preference->channel,
                'enabled' => $preference->enabled,
            ],
        ], 200, ['X-Request-ID' => $requestId]);
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
    }
}
