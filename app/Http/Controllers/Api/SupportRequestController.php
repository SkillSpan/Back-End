<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ReadinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RequestSupportHandoffRequest;
use App\Http\Requests\SendSupportMessageRequest;
use App\Http\Resources\SupportMessageResource;
use App\Http\Resources\SupportRequestResource;
use App\Models\AssistantInteraction;
use App\Models\SupportMessage;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\Profile\AdminProfileService;
use App\Services\Support\SupportRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * US-REC-01 follow-up — the learner side of the human handoff.
 *
 * A learner who asks for a person (or accepts the offer after the assistant
 * answered `insufficient_context`) gets a support request here, and can keep
 * talking in it. Replies from the support person arrive on the same thread via
 * the admin panel.
 *
 * Thin controller, like {@see AssistantController}: validation in FormRequests,
 * orchestration in SupportRequestService, presentation in the resources.
 */
class SupportRequestController extends Controller
{
    public function __construct(
        private readonly SupportRequestService $supportService,
        private readonly AdminProfileService $profileService,
    ) {}

    /**
     * Ask for a human.
     *
     * 201 with the request. If the learner already has an open request this
     * returns that one instead of creating a second — see
     * SupportRequestService::requestHandoff().
     */
    public function store(RequestSupportHandoffRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);
        $user = $request->user();

        try {
            $supportRequest = $this->supportService->requestHandoff(
                learner: $user,
                input: $request->validated(),
                profile: $user->studentProfile,
                interaction: $this->resolveOwnedInteraction($request, $user->id),
                requestId: $requestId,
            );

            return (new SupportRequestResource($supportRequest->load('assignee')))
                ->response()
                ->setStatusCode(201)
                ->header('X-Request-ID', $requestId);
        } catch (ReadinessException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (ValidationException $e) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'The request could not be processed.',
                'errors' => $e->errors(),
                'request_id' => $requestId,
            ], 422, ['X-Request-ID' => $requestId]);
        } catch (Throwable $e) {
            Log::error('Support handoff failed.', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'failure_reason' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'SUPPORT_HANDOFF_FAILED',
                'The request for support could not be created.',
                500,
                $requestId,
            );
        }
    }

    /** The caller's own support requests, newest first. */
    public function index(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $requests = SupportRequest::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate($this->perPage($request));

        return SupportRequestResource::collection($requests)
            ->response()
            ->header('X-Request-ID', $requestId);
    }

    /** One of the caller's own requests, with the thread. */
    public function show(Request $request, int $supportRequest): JsonResponse
    {
        $requestId = $this->requestId($request);

        $found = SupportRequest::query()
            ->where('user_id', $request->user()->id)
            ->with(['messages.sender', 'assignee'])
            ->find($supportRequest);

        if ($found === null) {
            // Same response whether the id is missing or belongs to someone
            // else — a learner must not be able to probe for valid ids.
            return $this->errorResponse(
                'SUPPORT_REQUEST_NOT_FOUND',
                'The support request is not available.',
                404,
                $requestId,
            );
        }

        return (new SupportRequestResource($found))
            ->response()
            ->header('X-Request-ID', $requestId);
    }

    /** Add a message to the caller's own open request. */
    public function message(SendSupportMessageRequest $request, int $supportRequest): JsonResponse
    {
        $requestId = $this->requestId($request);

        $found = SupportRequest::query()
            ->where('user_id', $request->user()->id)
            ->find($supportRequest);

        if ($found === null) {
            return $this->errorResponse(
                'SUPPORT_REQUEST_NOT_FOUND',
                'The support request is not available.',
                404,
                $requestId,
            );
        }

        try {
            $message = $this->supportService->postLearnerMessage(
                request: $found,
                learner: $request->user(),
                body: (string) $request->validated('body'),
            );

            return (new SupportMessageResource($message->load('sender')))
                ->response()
                ->setStatusCode(201)
                ->header('X-Request-ID', $requestId);
        } catch (ReadinessException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }
    }

    /**
     * GET /api/v1/support/avatar/{user}
     *
     * The face of whoever answered the caller in one of their own requests.
     *
     * WHY IT IS NOT A FIELD ON THE MESSAGE PAYLOAD
     * --------------------------------------------
     * `avatar_data` is a blob, and the project deliberately keeps it out of
     * JSON (see the `admin_profiles` migration): a thread with ten replies would
     * re-send the same photo ten times. The message payload already carries
     * `sender_id`, so the client builds this URL from it.
     *
     * SCOPED, and 404 for every miss — a stranger, a learner's own id, a person
     * with no photo, a user that does not exist. The endpoint therefore cannot
     * be walked to collect staff photos or to confirm which ids are real.
     */
    public function avatar(Request $request, User $user): Response
    {
        $learner = $request->user();

        $answeredThisLearner = SupportMessage::query()
            ->where('sender_id', $user->id)
            ->where('message_type', SupportMessage::TYPE_TEXT)
            ->whereHas('request', fn (Builder $query) => $query->where('user_id', $learner->id))
            ->exists();

        if (! $answeredThisLearner) {
            abort(404);
        }

        $profile = $user->adminProfile;

        if ($profile === null) {
            abort(404);
        }

        $binary = $this->profileService->avatarBinary($profile);

        if ($binary === null) {
            abort(404);
        }

        return response($binary, 200, [
            'Content-Type' => (string) $profile->avatar_mime,
            'Content-Length' => (string) strlen($binary),
            // Private: a learner's browser may cache the face of the person
            // helping them, but no shared proxy may.
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The interaction the handoff came from, if the caller owns it.
     *
     * Scoped through the caller's own student profile on purpose: passing
     * someone else's id yields null rather than a 403, so the endpoint never
     * confirms that another learner's interaction exists (BR-11).
     */
    private function resolveOwnedInteraction(Request $request, int $userId): ?AssistantInteraction
    {
        $interactionId = $request->input('source_interaction_id');

        if (! is_numeric($interactionId)) {
            return null;
        }

        $profileId = $request->user()->studentProfile?->id;

        if ($profileId === null) {
            return null;
        }

        return AssistantInteraction::query()
            ->where('id', (int) $interactionId)
            ->where('student_profile_id', $profileId)
            ->first();
    }

    private function perPage(Request $request): int
    {
        // Clamp, per the project's list-endpoint convention.
        return max(1, min(100, (int) $request->integer('per_page', 15)));
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
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
