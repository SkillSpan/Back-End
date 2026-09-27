<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CommunicationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ConversationController extends Controller
{
    public function __construct(private readonly ConversationService $conversationService) {}

    /**
     * POST /api/v1/connections/{connection}/conversations
     * Create a conversation for a mentor-student connection.
     */
    public function store(Request $request, int $connection): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $conversation = $this->conversationService->createConversation(
                connectionId: $connection,
                userId: $request->user()->id,
                request: $request,
            );

            return (new ConversationResource($conversation))
                ->response()
                ->setStatusCode(201)
                ->header('X-Request-ID', $requestId);
        } catch (CommunicationException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }
    }

    /**
     * GET /api/v1/conversations
     * List the authenticated user's conversations.
     */
    public function index(Request $request): JsonResponse
    {
        $conversations = $this->conversationService->getConversations(
            $request->user()->id,
        );

        return response()->json([
            'success' => true,
            'message' => 'Conversations retrieved successfully.',
            'data' => ConversationResource::collection($conversations->items()),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/conversations/{conversation}
     * Show a conversation with authorization check.
     */
    public function show(Request $request, int $conversation): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $conv = $this->conversationService->getConversation(
                conversationId: $conversation,
                userId: $request->user()->id,
            );

            return (new ConversationResource($conv))
                ->response()
                ->header('X-Request-ID', $requestId);
        } catch (CommunicationException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }
    }

    /**
     * POST /api/v1/conversations/{conversation}/messages
     * Send a message in a conversation.
     */
    public function sendMessage(StoreMessageRequest $request, int $conversation): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $message = $this->conversationService->sendMessage(
                conversationId: $conversation,
                senderId: $request->user()->id,
                body: $request->input('body'),
                type: $request->input('message_type', 'text'),
                metadata: $request->input('metadata'),
                request: $request,
            );

            return (new MessageResource($message))
                ->response()
                ->setStatusCode(201)
                ->header('X-Request-ID', $requestId);
        } catch (CommunicationException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }
    }

    /**
     * GET /api/v1/conversations/{conversation}/messages
     * Retrieve messages (paginated) with authorization check.
     */
    public function messages(Request $request, int $conversation): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $messages = $this->conversationService->getMessages(
                conversationId: $conversation,
                userId: $request->user()->id,
            );

            return response()->json([
                'success' => true,
                'message' => 'Messages retrieved successfully.',
                'data' => MessageResource::collection($messages->items()),
                'meta' => [
                    'current_page' => $messages->currentPage(),
                    'last_page' => $messages->lastPage(),
                    'per_page' => $messages->perPage(),
                    'total' => $messages->total(),
                ],
            ], 200, ['X-Request-ID' => $requestId]);
        } catch (CommunicationException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }
    }

    /**
     * POST /api/v1/conversations/{conversation}/read
     * Mark unread messages as read.
     */
    public function markAsRead(Request $request, int $conversation): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $count = $this->conversationService->markAsRead(
                conversationId: $conversation,
                userId: $request->user()->id,
            );

            return response()->json([
                'success' => true,
                'message' => "{$count} message(s) marked as read.",
                'data' => ['marked_read' => $count],
            ], 200, ['X-Request-ID' => $requestId]);
        } catch (CommunicationException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }
    }

    /**
     * GET /api/v1/conversations/{conversation}/status
     * Get communication status.
     */
    public function status(Request $request, int $conversation): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $status = $this->conversationService->getCommunicationStatus(
                conversationId: $conversation,
                userId: $request->user()->id,
            );

            return response()->json([
                'success' => true,
                'message' => 'Communication status retrieved successfully.',
                'data' => $status,
            ], 200, ['X-Request-ID' => $requestId]);
        } catch (CommunicationException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }
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
