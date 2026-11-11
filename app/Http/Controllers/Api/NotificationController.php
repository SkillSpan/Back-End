<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNotificationPreferenceRequest;
use App\Http\Resources\NotificationResource;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NotificationController extends Controller
{
    private const TOGGLE_CATEGORIES = [
        'new_applicant' => 'application',
        'project_status' => 'project_status',
        'messages' => 'message',
    ];

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
                'key' => $this->toggleKey($p->category),
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

    public function updatePreference(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        if ($this->isTogglePayload($request)) {
            return $this->updateToggles($request, $requestId);
        }

        $data = Validator::make($request->all(), (new UpdateNotificationPreferenceRequest)->rules())->validate();

        $preference = $this->notifications->setPreference(
            userId: $request->user()->id,
            category: $data['category'],
            channel: $data['channel'],
            enabled: (bool) $data['enabled'],
        );

        return response()->json([
            'success' => true,
            'message' => 'Notification preference updated successfully.',
            'data' => [
                'key' => $this->toggleKey($preference->category),
                'category' => $preference->category,
                'channel' => $preference->channel,
                'enabled' => $preference->enabled,
            ],
        ], 200, ['X-Request-ID' => $requestId]);
    }

    private function isTogglePayload(Request $request): bool
    {
        return $request->has('preferences')
            || $request->hasAny(array_keys(self::TOGGLE_CATEGORIES));
    }

    private function updateToggles(Request $request, string $requestId): JsonResponse
    {
        $payload = $request->input('preferences');

        if (! is_array($payload)) {
            $payload = $request->only(array_keys(self::TOGGLE_CATEGORIES));
        }

        $toggles = Validator::make(['preferences' => $payload], [
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*' => ['boolean'],
        ])->validate()['preferences'];

        $updated = [];

        foreach ($toggles as $key => $enabled) {
            $category = self::TOGGLE_CATEGORIES[$key] ?? null;

            if ($category === null) {
                continue;
            }

            $this->notifications->setPreference(
                userId: $request->user()->id,
                category: $category,
                channel: 'in_app',
                enabled: (bool) $enabled,
            );

            $updated[$key] = (bool) $enabled;
        }

        if ($updated === []) {
            throw ValidationException::withMessages([
                'preferences' => 'None of the provided keys is a known notification preference. Supported keys: '
                    .implode(', ', array_keys(self::TOGGLE_CATEGORIES)).'.',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification preferences updated successfully.',
            'data' => ['preferences' => $updated],
        ], 200, ['X-Request-ID' => $requestId]);
    }

    private function toggleKey(string $category): string
    {
        $key = array_search($category, self::TOGGLE_CATEGORIES, true);

        return $key === false ? $category : $key;
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
