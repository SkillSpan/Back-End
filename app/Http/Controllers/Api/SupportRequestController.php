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
use App\Support\SafeLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SupportRequestController extends Controller
{
    public function __construct(
        private readonly SupportRequestService $supportService,
        private readonly AdminProfileService $profileService,
    ) {}

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

            return $this->envelope(
                (new SupportRequestResource($supportRequest->load('assignee')))
                    ->response()
                    ->setStatusCode(201)
                    ->header('X-Request-ID', $requestId),
                'Support request created successfully.',
            );
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
                'failure_reason' => SafeLog::reason($e),
            ]);

            return $this->errorResponse(
                'SUPPORT_HANDOFF_FAILED',
                'The request for support could not be created.',
                500,
                $requestId,
            );
        }
    }

    public function index(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $requests = SupportRequest::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate($this->perPage($request));

        return $this->envelope(
            SupportRequestResource::collection($requests)
                ->response()
                ->header('X-Request-ID', $requestId),
            'Support requests retrieved successfully.',
        );
    }

    public function show(Request $request, int $supportRequest): JsonResponse
    {
        $requestId = $this->requestId($request);

        $found = SupportRequest::query()
            ->where('user_id', $request->user()->id)
            ->with(['messages.sender', 'assignee'])
            ->find($supportRequest);

        if ($found === null) {
            return $this->errorResponse(
                'SUPPORT_REQUEST_NOT_FOUND',
                'The support request is not available.',
                404,
                $requestId,
            );
        }

        return $this->envelope(
            (new SupportRequestResource($found))
                ->response()
                ->header('X-Request-ID', $requestId),
            'Support request retrieved successfully.',
        );
    }

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

            return $this->envelope(
                (new SupportMessageResource($message->load('sender')))
                    ->response()
                    ->setStatusCode(201)
                    ->header('X-Request-ID', $requestId),
                'Message sent successfully.',
            );
        } catch (ReadinessException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }
    }

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
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

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
        return max(1, min(100, (int) $request->integer('per_page', 15)));
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
    }

    private function envelope(JsonResponse $response, string $message): JsonResponse
    {
        $payload = $response->getData(true);

        $response->setData(array_merge(
            ['success' => true, 'message' => $message],
            is_array($payload) ? $payload : [],
        ));

        return $response;
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
