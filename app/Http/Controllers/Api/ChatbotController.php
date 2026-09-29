<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CommunicationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendChatbotMessageRequest;
use App\Http\Resources\MessageResource;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Chatbot communication endpoints for a mentor-student conversation.
 *
 * A "chatbot message" is an automated message posted into a conversation
 * (reminders, prompts, guided next-steps) and tagged message_type=chatbot
 * so it is separable from human traffic in both the API and the audit
 * trail. Authorization is identical to human messaging: only the two
 * participants may post or read, enforced in ConversationService.
 */
class ChatbotController extends Controller
{
    public function __construct(private readonly ConversationService $conversationService) {}

    /**
     * POST /api/v1/conversations/{conversation}/chatbot/messages
     * Post a chatbot message into a conversation.
     */
    public function send(SendChatbotMessageRequest $request, int $conversation): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $metadata = $request->input('metadata', []);

            if ($request->filled('source')) {
                $metadata['source'] = $request->input('source');
            }

            $message = $this->conversationService->sendChatbotMessage(
                conversationId: $conversation,
                actorId: $request->user()->id,
                body: $request->input('body'),
                metadata: $metadata,
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
     * GET /api/v1/conversations/{conversation}/chatbot/messages
     * Retrieve only the chatbot messages in a conversation.
     */
    public function messages(Request $request, int $conversation): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $messages = $this->conversationService->getChatbotMessages(
                conversationId: $conversation,
                userId: $request->user()->id,
            );

            return response()->json([
                'success' => true,
                'message' => 'Chatbot messages retrieved successfully.',
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
