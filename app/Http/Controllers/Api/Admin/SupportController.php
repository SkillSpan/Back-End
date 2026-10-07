<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ReadinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendSupportMessageRequest;
use App\Http\Resources\SupportMessageResource;
use App\Http\Resources\SupportRequestResource;
use App\Models\ProfessionalProfile;
use App\Models\SupportMessage;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\Support\SupportRequestService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The support inbox — where an escalated assistant conversation is answered.
 *
 * WHY IT IS NOT ADMIN-ONLY
 * ------------------------
 * The person who answers is the mentor already connected to the learner, not
 * an administrator. Gating this on the `admin` middleware would notify a
 * mentor and then refuse them the page the notification links to. So the route
 * carries only `auth` + `account.active`, and the two audiences are separated
 * here instead:
 *
 *   - an administrator sees EVERY request — they are the safety net for the
 *     ones no mentor is connected to, which would otherwise sit unread;
 *   - a mentor sees ONLY the requests assigned to them. A mentor must never be
 *     able to read another mentor's learner, so this is a hard scope, not a
 *     default the UI happens to apply.
 *
 * Anything else gets a 403. The visibility rule lives in one place
 * ({@see scopedQuery()}) so the list, the detail and the reply can never drift
 * apart — a request you cannot list is a request you cannot open.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * It never writes `status` or `assigned_to` directly. Both go through
 * SupportRequestService, which owns the claim-on-first-reply rule and the
 * notifications.
 */
class SupportController extends Controller
{
    /** Relations the inbox renders. */
    private const REQUEST_WITH = [
        'user:id,name,email',
        'assignee:id,name,email',
        'studentProfile:id,user_id',
    ];

    /** Relations the detail screen adds on top. */
    private const THREAD_WITH = [
        'messages.sender:id,name,email',
    ];

    public function __construct(private readonly SupportRequestService $supportService) {}

    /**
     * GET /admin/api/support
     *
     * The inbox. Server-side pagination so a long backlog never arrives whole.
     */
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
                // "Unread" is relative to the viewer, so the count has to be
                // built with their id — a shared `read_at` is not enough.
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

    /**
     * GET /admin/api/support/{supportRequest}
     *
     * One request with its full thread. Opening a thread marks the other
     * side's messages as read, which is what the learner's unread badge and
     * the inbox counter are derived from.
     */
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

        // `load()` re-queries the thread, which is what picks up the read
        // timestamps just written. `fresh()` would have dropped the
        // `messages_count` the inbox row needs, and would not re-query the
        // relation at all.
        $model->load(array_merge(self::REQUEST_WITH, self::THREAD_WITH));

        return response()->json([
            'success' => true,
            'message' => 'Support request retrieved successfully.',
            'data' => (new SupportRequestResource($model))->resolve($request),
            'request_id' => $requestId,
            'viewer' => $this->viewer($request),
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * POST /admin/api/support/{supportRequest}/messages
     *
     * Reply to the learner. The first reply claims an unclaimed request, so a
     * thread can never be answered by someone who is not recorded as its owner.
     */
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

    /**
     * POST /admin/api/support/{supportRequest}/assign
     *
     * Claim the request for the caller.
     */
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

        // Claiming is idempotent: whoever already owns it keeps it. Stealing
        // another person's thread mid-conversation would silently drop them
        // out of a conversation the learner believes they are in.
        if ($model->assigned_to === null) {
            $this->supportService->assign($model, $request->user());
        }

        return $this->requestResponse($model->fresh(), 'Support request assigned successfully.', $requestId);
    }

    /**
     * POST /admin/api/support/{supportRequest}/resolve
     *
     * Close the thread as answered. The learner is notified.
     */
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

    // ── Visibility ────────────────────────────────────────────────────────

    /**
     * Refuse anyone who is neither an administrator nor a mentor.
     *
     * Without this the scope below would quietly return an empty inbox to an
     * unrelated account, which reads as "you have no requests" rather than
     * "you are not allowed here". The page route answers 403 for the same
     * reason.
     */
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

    /**
     * The requests the caller is allowed to see, as a query.
     *
     * An administrator sees everything; a mentor sees only their own
     * assignments. An account with neither capability sees nothing at all —
     * the empty result is deliberate, so a stray role cannot read a private
     * conversation by accident.
     */
    private function scopedQuery(Request $request): Builder
    {
        $user = $request->user();

        $query = SupportRequest::query();

        if ($this->isAdministrator($user)) {
            return $query;
        }

        return $query->where('assigned_to', $user->id);
    }

    /**
     * May this account open the support inbox at all?
     *
     * Used by the Blade route, which has to answer with a 403 page rather
     * than a JSON body.
     */
    public static function isSupportAgent(User $user): bool
    {
        return $user->hasRole('admin') || self::isMentor($user);
    }

    private static function isMentor(User $user): bool
    {
        // Mentor identity is a ProfessionalProfile attribute, not a role slug
        // — see EnsureUserIsMentor. Any type='mentor' profile counts here:
        // the verified-only gate belongs on the routes that grant mentor
        // powers, and an unverified mentor still needs to answer the learner
        // they were already assigned.
        return ProfessionalProfile::query()
            ->where('user_id', $user->id)
            ->where('type', 'mentor')
            ->exists();
    }

    private function isAdministrator(User $user): bool
    {
        return $user->hasRole('admin');
    }

    // ── Read tracking ─────────────────────────────────────────────────────

    /**
     * Mark the messages the caller did NOT send as read.
     *
     * Scoped to the other side on purpose: marking your own messages read would
     * make the learner's "support has seen this" signal meaningless.
     */
    private function markRead(SupportRequest $supportRequest, User $reader): void
    {
        $supportRequest->messages()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $reader->id)
            ->update(['read_at' => now(), 'read_by' => $reader->id]);
    }

    // ── Filters ───────────────────────────────────────────────────────────

    private function applyFilters(Builder $query, Request $request): void
    {
        $status = $request->query('status');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        // `open` is a convenience filter for "anything still needing an
        // answer", which is what an operator actually wants to see first.
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

            // Grouped so the OR cannot escape the rest of the WHERE clause and
            // widen the result set — the classic search-filter bug.
            $query->where(function (Builder $inner) use ($term) {
                $inner->where('subject', 'like', $term)
                    ->orWhere('id', 'like', $term)
                    ->orWhereHas('user', fn (Builder $user) => $user
                        ->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term));
            });
        }
    }

    /** @return array<string, string|null> */
    private function appliedFilters(Request $request): array
    {
        return [
            'status' => $request->query('status'),
            'scope' => $request->query('scope'),
            'reason' => $request->query('reason'),
            'q' => $request->query('q'),
        ];
    }

    /**
     * Counts for the summary cards.
     *
     * Scoped like the list, so a mentor's cards describe their own queue
     * rather than the whole platform — a card that says "12 pending" while the
     * list shows none is worse than no card.
     *
     * @return array<string, int>
     */
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

    /**
     * "This message is waiting for the viewer to read it."
     *
     * System messages are excluded on purpose. The handoff marker is written by
     * the service, not by a person, and it is never read or replied to — so
     * counting it would mark every thread unread from the moment it is created,
     * which is exactly the signal the badge is supposed to carry.
     */
    private function unreadConstraint(Builder $message, int $viewerId): Builder
    {
        return $message
            ->whereNull('read_at')
            ->where('message_type', SupportMessage::TYPE_TEXT)
            ->where('sender_id', '!=', $viewerId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
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

    /** @return array{id: int, name: string, is_admin: bool, is_mentor: bool} */
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
        // Clamp, per the project's list-endpoint convention.
        return max(1, min(100, (int) $request->integer('per_page', 20)));
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
    }

    private function notFound(int $supportRequestId, string $requestId): JsonResponse
    {
        // Same wording whether the id is absent or merely out of scope, so the
        // inbox cannot be used to enumerate other people's requests.
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
            'failure_reason' => $e->getMessage(),
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
