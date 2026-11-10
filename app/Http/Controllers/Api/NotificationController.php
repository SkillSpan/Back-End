<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNotificationPreferenceRequest;
use App\Http\Resources\NotificationResource;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $notifications = $this->notifications->list(
            userId: $request->user()->id,
            filters: [
                'category' => $request->query('category'),
                'unread_only' => $request->boolean('unread_only') || $request->boolean('unread'),
            ],
            perPage: $this->perPage($request),
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

    public function markAsRead(Request $request, int $notification): JsonResponse
    {
        $requestId = $this->requestId($request);

        $updated = $this->notifications->markAsRead($request->user()->id, $notification);

        if (! $updated) {
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

    private function perPage(Request $request): int
    {
        $default = (int) config('company.notifications.default_per_page', 20);
        $max = max(1, (int) config('company.notifications.max_per_page', 50));

        $requested = $request->integer('per_page', $default);

        return max(1, min($max, $requested));
    }
}
