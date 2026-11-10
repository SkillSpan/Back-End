<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ReadinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendSupportMessageRequest;
use App\Http\Resources\SupportMessageResource;
use App\Http\Resources\SupportRequestResource;
use App\Models\SupportMessage;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\Support\SupportRequestService;
use App\Support\PanelAccess;
use App\Support\SafeLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SupportController extends Controller
{
    private const REQUEST_WITH = [
        'user:id,name,email',
        'assignee:id,name,email',
        'studentProfile:id,user_id',
    ];

    private const THREAD_WITH = [
        'messages.sender:id,name,email',
    ];

    public function __construct(private readonly SupportRequestService $supportService) {}

    public function index(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $query = $this->scopedQuery($request)
            ->with(self::REQUEST_WITH)
            ->withCount([
                'messages',
                'messages as unread_count' => fn (Builder $message) => $this->unreadConstraint($message, $request->user()->id),
            ])
            ->latest('last_message_at')
            ->latest('id');

        $this->applyFilters($query, $request);

        $perPage = $this->perPage($request);

        $requests = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'success' => true,
            'message' => 'Support requests retrieved successfully.',
            'data' => $this->paginatedPayload($requests),
            'stats' => $this->stats($request),
            'request_id' => $requestId,
            'filters' => $this->appliedFilters($request),
            'viewer' => $this->viewer($request),
        ], 200, ['X-Request-ID' => $requestId]);
    }

    public function show(Request $request, int $supportRequest): JsonResponse
    {
        $requestId = $this->requestId($request);

        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $model = $this->scopedQuery($request)
            ->with(array_merge(self::REQUEST_WITH, self::THREAD_WITH))
            ->withCount('messages')
            ->find($supportRequest);

        if ($model === null) {
            return $this->notFound($supportRequest, $requestId);
        }

        $this->markRead($model, $request->user());

        $model->load(array_merge(self::REQUEST_WITH, self::THREAD_WITH));

        return response()->json([
            'success' => true,
            'message' => 'Support request retrieved successfully.',
            'data' => (new SupportRequestResource($model))->resolve($request),
            'request_id' => $requestId,
            'viewer' => $this->viewer($request),
        ], 200, ['X-Request-ID' => $requestId]);
    }

    public function reply(SendSupportMessageRequest $request, int $supportRequest): JsonResponse
    {
        $requestId = $this->requestId($request);

        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $model = $this->scopedQuery($request)->find($supportRequest);

        if ($model === null) {
            return $this->notFound($supportRequest, $requestId);
        }

        try {
            $message = $this->supportService->postSupportMessage(
                request: $model,
                support: $request->user(),
                body: (string) $request->validated('body'),
            );

            return (new SupportMessageResource($message->load('sender')))
                ->response()
                ->setStatusCode(201)
                ->header('X-Request-ID', $requestId);
        } catch (ReadinessException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['support_request_id' => $supportRequest]);
        }
    }

    public function assign(Request $request, int $supportRequest): JsonResponse
    {
        $requestId = $this->requestId($request);

        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $model = $this->scopedQuery($request)->find($supportRequest);

        if ($model === null) {
            return $this->notFound($supportRequest, $requestId);
        }

        if ($model->assigned_to === null) {
            $this->supportService->assign($model, $request->user());
        }

        return $this->requestResponse($model->fresh(), 'Support request assigned successfully.', $requestId);
    }

    public function resolve(Request $request, int $supportRequest): JsonResponse
    {
        $requestId = $this->requestId($request);

        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $model = $this->scopedQuery($request)->find($supportRequest);

        if ($model === null) {
            return $this->notFound($supportRequest, $requestId);
        }

        $this->supportService->resolve($model, $request->user());

        return $this->requestResponse($model->fresh(), 'Support request resolved successfully.', $requestId);
    }

    public function destroyMessage(Request $request, int $supportRequest, int $message): JsonResponse
    {
        $requestId = $this->requestId($request);

        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $model = $this->scopedQuery($request)->find($supportRequest);

        if ($model === null) {
            return $this->notFound($supportRequest, $requestId);
        }

        $target = $model->messages()->find($message);

        if ($target === null) {
            return $this->errorResponse(
                'SUPPORT_MESSAGE_NOT_FOUND',
                'The message is not available.',
                404,
                $requestId,
            );
        }

        Log::info('Support message deleted.', [
            'request_id' => $requestId,
            'support_request_id' => $model->id,
            'support_message_id' => $target->id,
            'message_type' => $target->message_type,
            'deleted_by' => $request->user()->id,
        ]);

        $target->delete();

        $model->forceFill(['last_message_at' => $model->messages()->max('created_at')])->save();

        return response()->json([
            'success' => true,
            'message' => 'Support message deleted successfully.',
            'data' => ['id' => (int) $message],
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    public function destroy(Request $request, int $supportRequest): JsonResponse
    {
        $requestId = $this->requestId($request);

        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $model = $this->scopedQuery($request)->find($supportRequest);

        if ($model === null) {
            return $this->notFound($supportRequest, $requestId);
        }

        Log::info('Support request deleted.', [
            'request_id' => $requestId,
            'support_request_id' => $model->id,
            'status' => $model->status,
            'assigned_to' => $model->assigned_to,
            'deleted_by' => $request->user()->id,
        ]);

        $model->delete();

        return response()->json([
            'success' => true,
            'message' => 'Support request deleted successfully.',
            'data' => ['id' => (int) $supportRequest],
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    private function guard(Request $request): ?JsonResponse
    {
        if (self::isSupportAgent($request->user())) {
            return null;
        }

        return $this->errorResponse(
            'SUPPORT_FORBIDDEN',
            'You do not have access to the support inbox.',
            403,
            $this->requestId($request),
        );
    }

    private function scopedQuery(Request $request): Builder
    {
        $user = $request->user();

        $query = SupportRequest::query();

        if ($this->isAdministrator($user)) {
            return $query;
        }

        return $query->where('assigned_to', $user->id);
    }

    public static function isSupportAgent(User $user): bool
    {
        return PanelAccess::allows($user);
    }

    private static function isMentor(User $user): bool
    {
        return PanelAccess::isMentor($user);
    }

    private function isAdministrator(User $user): bool
    {
        return PanelAccess::isAdministrator($user);
    }

    private function markRead(SupportRequest $supportRequest, User $reader): void
    {
        $supportRequest->messages()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $reader->id)
            ->update(['read_at' => now(), 'read_by' => $reader->id]);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $status = $request->query('status');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        if ($request->query('scope') === 'open') {
            $query->open();
        }

        $reason = $request->query('reason');

        if (is_string($reason) && $reason !== '') {
            $query->where('reason', $reason);
        }

        $search = $request->query('q');

        if (is_string($search) && trim($search) !== '') {
            $term = '%'.trim($search).'%';

            $query->where(function (Builder $inner) use ($term) {
                $inner->where('subject', 'like', $term)
                    ->orWhere('id', 'like', $term)
                    ->orWhereHas('user', fn (Builder $user) => $user
                        ->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term));
            });
        }
    }

    private function appliedFilters(Request $request): array
    {
        return [
            'status' => $request->query('status'),
            'scope' => $request->query('scope'),
            'reason' => $request->query('reason'),
            'q' => $request->query('q'),
        ];
    }

    private function stats(Request $request): array
    {
        $base = fn (): Builder => $this->scopedQuery($request);

        return [
            'total' => $base()->count(),
            'pending' => $base()->where('status', SupportRequest::STATUS_PENDING)->count(),
            'assigned' => $base()->where('status', SupportRequest::STATUS_ASSIGNED)->count(),
            'resolved' => $base()->where('status', SupportRequest::STATUS_RESOLVED)->count(),
            'unread' => $base()
                ->whereHas('messages', fn (Builder $message) => $this->unreadConstraint($message, $request->user()->id))
                ->count(),
        ];
    }

    private function unreadConstraint(Builder $message, int $viewerId): Builder
    {
        return $message
            ->whereNull('read_at')
            ->where('message_type', SupportMessage::TYPE_TEXT)
            ->where('sender_id', '!=', $viewerId);
    }

    private function paginatedPayload(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => SupportRequestResource::collection($paginator->getCollection())
                ->resolve(request()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    private function requestResponse(SupportRequest $model, string $message, string $requestId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => (new SupportRequestResource($model->load(self::REQUEST_WITH)))->resolve(request()),
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    private function viewer(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'is_admin' => $this->isAdministrator($user),
            'is_mentor' => self::isMentor($user),
        ];
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->integer('per_page', 20)));
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
    }

    private function notFound(int $supportRequestId, string $requestId): JsonResponse
    {
        return $this->errorResponse(
            'SUPPORT_REQUEST_NOT_FOUND',
            'The support request is not available.',
            404,
            $requestId,
        );
    }

    private function unexpected(Throwable $e, string $requestId, array $context = []): JsonResponse
    {
        Log::error('Admin support request failed.', array_merge([
            'request_id' => $requestId,
            'failure_reason' => SafeLog::reason($e),
        ], $context));

        return $this->errorResponse(
            'SUPPORT_REQUEST_FAILED',
            'The support request could not be completed.',
            500,
            $requestId,
        );
    }

    private function errorResponse(
        string $code,
        string $message,
        int $status,
        string $requestId,
        array $details = [],
    ): JsonResponse {
        $payload = [
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ];

        if ($details !== []) {
            $payload['details'] = $details;
        }

        return response()->json($payload, $status, ['X-Request-ID' => $requestId]);
    }
}
