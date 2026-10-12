<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI documentation for the mentor-student communication endpoints.
 * Two controllers share the conversation path space:
 *
 *  - App\Http\Controllers\Api\ConversationController — human conversations and messages
 *  - App\Http\Controllers\Api\ChatbotController      — automated (message_type=chatbot) messages
 *
 * All endpoints require auth:sanctum + account.active. Participant
 * authorization is enforced inside App\Services\ConversationService (not at
 * the middleware layer), which surfaces as HTTP 403 with a machine-readable
 * `code` in the error envelope.
 *
 * Paths are relative to the `/api` server, so they carry the `/v1` prefix
 * exactly as registered in routes/api.php.
 */
class ConversationEndpoints
{
    #[OA\Post(
        path: '/v1/connections/{connection}/conversations',
        operationId: 'conversationsStore',
        tags: ['Conversations'],
        summary: 'Create (or return) the conversation for a connection',
        description: 'Creates the conversation that belongs to a mentor-student connection. '
            .'Idempotent: if an active conversation already exists for the connection it is returned '
            .'unchanged instead of creating a second one, so the endpoint is safe to call repeatedly. '
            .'The caller must be one of the two participants of the connection, otherwise 403. The '
            .'connection must still be `pending` or `active`; a disconnected connection returns 422. '
            .'No request body is accepted — the conversation is derived entirely from the connection. '
            .'Protected by auth:sanctum + account.active.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'connection',
                in: 'path',
                required: true,
                description: 'Id of the mentor-student connection the conversation belongs to.',
                schema: new OA\Schema(type: 'integer', example: 17),
            ),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Conversation created (or the existing active conversation returned).',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Conversation'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'The caller is not a participant in this connection (`NOT_A_PARTICIPANT`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'The connection does not exist.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'The connection is no longer active (`CONNECTION_NOT_ACTIVE`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(): void {}

    #[OA\Get(
        path: '/v1/conversations',
        operationId: 'conversationsIndex',
        tags: ['Conversations'],
        summary: "List the authenticated user's conversations",
        description: 'Returns the active conversations the authenticated user participates in, either as '
            .'mentor or as student, most recently active first (`last_message_at` descending). Each '
            .'conversation includes its connection and the two participants. Results are paginated using '
            .'the configured page size. Protected by auth:sanctum + account.active.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Conversations retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Conversations retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Conversation'),
                        ),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 3),
                                new OA\Property(property: 'per_page', type: 'integer', example: 20),
                                new OA\Property(property: 'total', type: 'integer', example: 47),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'The account is not active.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(): void {}

    #[OA\Get(
        path: '/v1/conversations/{conversation}',
        operationId: 'conversationsShow',
        tags: ['Conversations'],
        summary: 'Show a single conversation',
        description: 'Returns one conversation together with its connection. The caller must be one of the '
            .'two participants, otherwise 403 (`UNAUTHORIZED_CONVERSATION`). Protected by auth:sanctum '
            .'+ account.active.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'conversation',
                in: 'path',
                required: true,
                description: 'Id of the conversation.',
                schema: new OA\Schema(type: 'integer', example: 88),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Conversation retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Conversation'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'The caller is not a participant of this conversation (`UNAUTHORIZED_CONVERSATION`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'The conversation does not exist.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(): void {}

    #[OA\Post(
        path: '/v1/conversations/{conversation}/messages',
        operationId: 'conversationsSendMessage',
        tags: ['Conversations'],
        summary: 'Send a message in a conversation',
        description: 'Posts a human message into a conversation and notifies the other participant '
            .'(honouring their notification preferences). The caller must be a participant (403) and the '
            .'conversation must still be active (422). The body is validated against the configured '
            .'`communication.message_max_length` and is also re-checked in the service. Protected by '
            .'auth:sanctum + account.active.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'conversation',
                in: 'path',
                required: true,
                description: 'Id of the conversation to post into.',
                schema: new OA\Schema(type: 'integer', example: 88),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['body'],
                properties: [
                    new OA\Property(property: 'body', type: 'string', maxLength: 5000, example: 'Hi! Ready for our session tomorrow?'),
                    new OA\Property(property: 'message_type', type: 'string', nullable: true, enum: ['text', 'chatbot'], example: 'text', description: 'Defaults to `text` when omitted. `system` is refused (422): that type is machine-authored and no participant may set it.'),
                    new OA\Property(property: 'metadata', type: 'object', nullable: true, additionalProperties: true, example: ['attachments' => ['spec.pdf']]),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Message sent.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Message'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'The caller is not a participant of this conversation (`UNAUTHORIZED_CONVERSATION`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'The conversation does not exist.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failure (missing/too-long body, invalid message_type), or the conversation is no longer active (`CONVERSATION_NOT_ACTIVE` / `MESSAGE_TOO_LONG`).', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function sendMessage(): void {}

    #[OA\Get(
        path: '/v1/conversations/{conversation}/messages',
        operationId: 'conversationsMessages',
        tags: ['Conversations'],
        summary: 'List the messages of a conversation',
        description: 'Returns the conversation messages, newest first, paginated. The caller must be a '
            .'participant (403). Protected by auth:sanctum + account.active.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'conversation',
                in: 'path',
                required: true,
                description: 'Id of the conversation.',
                schema: new OA\Schema(type: 'integer', example: 88),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Messages retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Messages retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Message'),
                        ),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 5),
                                new OA\Property(property: 'per_page', type: 'integer', example: 50),
                                new OA\Property(property: 'total', type: 'integer', example: 213),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'The caller is not a participant of this conversation (`UNAUTHORIZED_CONVERSATION`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'The conversation does not exist.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function messages(): void {}

    #[OA\Post(
        path: '/v1/conversations/{conversation}/read',
        operationId: 'conversationsMarkAsRead',
        tags: ['Conversations'],
        summary: 'Mark the conversation messages as read',
        description: 'Marks every unread message sent by the other participant as read (setting `read_at` '
            .'and `read_by` to the caller) and returns how many rows were affected. The caller must be a '
            .'participant (403). Protected by auth:sanctum + account.active. No request body is required.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'conversation',
                in: 'path',
                required: true,
                description: 'Id of the conversation.',
                schema: new OA\Schema(type: 'integer', example: 88),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Messages marked as read.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: '2 message(s) marked as read.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'marked_read', type: 'integer', example: 2),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'The caller is not a participant of this conversation (`UNAUTHORIZED_CONVERSATION`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'The conversation does not exist.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function markAsRead(): void {}

    #[OA\Get(
        path: '/v1/conversations/{conversation}/status',
        operationId: 'conversationsStatus',
        tags: ['Conversations'],
        summary: 'Get the communication status of a conversation',
        description: 'Returns a lightweight status summary: the conversation state, the number of messages '
            .'from the other participant that the caller has not read yet, the last message timestamp and a '
            .'short preview, and when the conversation retention window expires. The caller must be a '
            .'participant (403). Protected by auth:sanctum + account.active.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'conversation',
                in: 'path',
                required: true,
                description: 'Id of the conversation.',
                schema: new OA\Schema(type: 'integer', example: 88),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Communication status retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Communication status retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'conversation_id', type: 'integer', example: 88),
                                new OA\Property(property: 'status', type: 'string', example: 'active'),
                                new OA\Property(property: 'unread_count', type: 'integer', example: 2),
                                new OA\Property(property: 'last_message_at', type: 'string', format: 'date-time', nullable: true, example: '2026-09-30T14:22:11+00:00'),
                                new OA\Property(property: 'last_message_preview', type: 'string', nullable: true, example: 'Hi! Ready for our session tomorrow?'),
                                new OA\Property(property: 'retention_expires_at', type: 'string', format: 'date-time', nullable: true, example: '2027-09-30T14:22:11+00:00'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'The caller is not a participant of this conversation (`UNAUTHORIZED_CONVERSATION`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'The conversation does not exist.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function status(): void {}

    #[OA\Post(
        path: '/v1/conversations/{conversation}/chatbot/messages',
        operationId: 'chatbotSend',
        tags: ['Conversations'],
        summary: 'Post an automated chatbot message into a conversation',
        description: 'Posts an automated message into a conversation. The message is always stored with '
            .'`message_type` = `chatbot` — the client cannot override it — and the metadata is merged with '
            .'`source` so automated traffic stays separable from human messages in both the API and the '
            .'audit trail. The other participant is notified (honouring their preferences). The caller must '
            .'be a participant (403) and the conversation must still be active (422). Protected by '
            .'auth:sanctum + account.active.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'conversation',
                in: 'path',
                required: true,
                description: 'Id of the conversation to post into.',
                schema: new OA\Schema(type: 'integer', example: 88),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['body'],
                properties: [
                    new OA\Property(property: 'body', type: 'string', maxLength: 5000, example: 'Reminder: your next mentoring session is tomorrow at 10:00.'),
                    new OA\Property(property: 'metadata', type: 'object', nullable: true, additionalProperties: true, example: ['prompt' => 'session_reminder']),
                    new OA\Property(property: 'source', type: 'string', nullable: true, maxLength: 100, example: 'session_reminder', description: 'Optional tag stored under `metadata.source`; defaults to `chatbot`.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Chatbot message posted.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Message'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'The caller is not a participant of this conversation (`UNAUTHORIZED_CONVERSATION`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'The conversation does not exist.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failure (missing/too-long body, invalid source), or the conversation is no longer active (`CONVERSATION_NOT_ACTIVE` / `MESSAGE_TOO_LONG`).', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function chatbotSend(): void {}

    #[OA\Get(
        path: '/v1/conversations/{conversation}/chatbot/messages',
        operationId: 'chatbotMessages',
        tags: ['Conversations'],
        summary: 'List the chatbot messages of a conversation',
        description: 'Returns only the messages with `message_type` = `chatbot` in the conversation, newest '
            .'first, paginated. The caller must be a participant (403). Protected by auth:sanctum + '
            .'account.active.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'conversation',
                in: 'path',
                required: true,
                description: 'Id of the conversation.',
                schema: new OA\Schema(type: 'integer', example: 88),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Chatbot messages retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Chatbot messages retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Message'),
                        ),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 1),
                                new OA\Property(property: 'per_page', type: 'integer', example: 50),
                                new OA\Property(property: 'total', type: 'integer', example: 6),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'The caller is not a participant of this conversation (`UNAUTHORIZED_CONVERSATION`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'The conversation does not exist.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function chatbotMessages(): void {}
}
