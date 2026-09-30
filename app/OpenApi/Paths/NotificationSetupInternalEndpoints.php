<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI documentation for the notifications feed, the setup (bootstrap)
 * endpoints and the internal server-to-server baseline-items endpoint.
 *
 * Paths are relative to the `/api` server, so they carry the `/v1` prefix
 * exactly as registered in routes/api.php.
 */
class NotificationSetupInternalEndpoints
{
    #[OA\Get(
        path: '/v1/notifications',
        operationId: 'notificationsIndex',
        tags: ['Notifications'],
        summary: 'List the caller\'s notifications',
        description: 'Returns the authenticated user\'s own notifications, newest first, paginated '
            .'(20 per page). Every row is scoped to the caller\'s user_id, so a user can never read '
            .'another user\'s feed. Requires an active account.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'category', in: 'query', required: false, description: 'Filter by notification category.', schema: new OA\Schema(type: 'string', maxLength: 100, example: 'application')),
            new OA\Parameter(name: 'unread_only', in: 'query', required: false, description: 'When truthy, only unread notifications are returned.', schema: new OA\Schema(type: 'boolean', example: true)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number.', schema: new OA\Schema(type: 'integer', minimum: 1, example: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Notifications retrieved successfully.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Notifications retrieved successfully.'),
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Notification')),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 3),
                                new OA\Property(property: 'per_page', type: 'integer', example: 20),
                                new OA\Property(property: 'total', type: 'integer', example: 42),
                                new OA\Property(property: 'unread_count', type: 'integer', example: 5),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Account is not active.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function notificationsIndex(): void {}

    #[OA\Get(
        path: '/v1/notifications/unread-count',
        operationId: 'notificationsUnreadCount',
        tags: ['Notifications'],
        summary: 'Get the caller\'s unread notification count',
        description: 'Cheap polling endpoint for the unread badge. Optionally scoped to a single '
            .'category. Requires an active account.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'category', in: 'query', required: false, description: 'Count unread notifications in this category only.', schema: new OA\Schema(type: 'string', maxLength: 100, example: 'application')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Unread count retrieved successfully.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Unread count retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [new OA\Property(property: 'unread_count', type: 'integer', example: 5)],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Account is not active.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function notificationsUnreadCount(): void {}

    #[OA\Post(
        path: '/v1/notifications/read-all',
        operationId: 'notificationsMarkAllAsRead',
        tags: ['Notifications'],
        summary: 'Mark all of the caller\'s notifications as read',
        description: 'Marks every unread notification belonging to the authenticated user as read, '
            .'optionally limited to a single category. Returns how many rows were affected. '
            .'Requires an active account.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'category', type: 'string', nullable: true, maxLength: 100, description: 'Only mark unread notifications in this category.', example: 'application'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Notifications marked as read.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: '3 notification(s) marked as read.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [new OA\Property(property: 'marked_read', type: 'integer', example: 3)],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Account is not active.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function notificationsMarkAllAsRead(): void {}

    #[OA\Get(
        path: '/v1/notifications/preferences',
        operationId: 'notificationsPreferences',
        tags: ['Notifications'],
        summary: 'List the caller\'s explicit notification preferences',
        description: 'Returns the explicit preference rows for the authenticated user (rows only, not '
            .'defaults — absence of a row means the category/channel is enabled). Ordered by category. '
            .'Requires an active account.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Notification preferences retrieved successfully.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Notification preferences retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'category', type: 'string', example: 'application'),
                                    new OA\Property(property: 'channel', type: 'string', enum: ['in_app', 'email'], example: 'in_app'),
                                    new OA\Property(property: 'enabled', type: 'boolean', example: true),
                                ],
                            ),
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Account is not active.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function notificationsPreferences(): void {}

    #[OA\Put(
        path: '/v1/notifications/preferences',
        operationId: 'notificationsUpdatePreference',
        tags: ['Notifications'],
        summary: 'Upsert a single notification preference',
        description: 'Creates or updates the preference row for the given category/channel pair. '
            .'Channels are constrained to the ones the system actually dispatches on. Requires an '
            .'active account.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['category', 'channel', 'enabled'],
                properties: [
                    new OA\Property(property: 'category', type: 'string', maxLength: 100, example: 'application'),
                    new OA\Property(property: 'channel', type: 'string', enum: ['in_app', 'email'], example: 'in_app'),
                    new OA\Property(property: 'enabled', type: 'boolean', example: false),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Notification preference updated successfully.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Notification preference updated successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'category', type: 'string', example: 'application'),
                                new OA\Property(property: 'channel', type: 'string', enum: ['in_app', 'email'], example: 'in_app'),
                                new OA\Property(property: 'enabled', type: 'boolean', example: false),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Account is not active.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error (unknown channel, missing fields, …).', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function notificationsUpdatePreference(): void {}

    #[OA\Post(
        path: '/v1/notifications/{notification}/read',
        operationId: 'notificationsMarkAsRead',
        tags: ['Notifications'],
        summary: 'Mark one notification as read',
        description: 'Marks a single notification belonging to the authenticated user as read. The row '
            .'is matched by id AND user_id, so a notification that does not exist or belongs to someone '
            .'else returns the same 404. Requires an active account.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'notification', in: 'path', required: true, description: 'Notification id.', schema: new OA\Schema(type: 'integer', example: 42)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Notification marked as read.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Notification'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 404,
                description: 'The specified notification was not found (or belongs to another user).',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'code', type: 'string', example: 'NOTIFICATION_NOT_FOUND'),
                        new OA\Property(property: 'message', type: 'string', example: 'The specified notification was not found.'),
                        new OA\Property(property: 'request_id', type: 'string', example: 'b1e2d3c4-5678-90ab-cdef-1234567890ab'),
                    ],
                ),
            ),
        ],
    )]
    public function notificationsMarkAsRead(): void {}

    #[OA\Post(
        path: '/v1/setup/create-admin',
        operationId: 'setupCreateAdmin',
        tags: ['Setup'],
        summary: 'Create an administrator account (bootstrap)',
        description: 'Permanent bootstrap endpoint for creating admin accounts. It is gated by the '
            .'ADMIN_SETUP_SECRET environment value supplied as the `secret` body field — this is NOT '
            .'Sanctum authentication and the endpoint is public. The account is created active and '
            .'email-verified, granted the `admin` role, and a bearer token is returned. Throttled to '
            .'5 requests per minute.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['secret', 'name', 'email', 'password'],
                properties: [
                    new OA\Property(property: 'secret', type: 'string', description: 'Shared ADMIN_SETUP_SECRET value.', example: 'your-admin-setup-secret'),
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Platform Admin'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 255, example: 'admin@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, example: 'StrongPass123'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Admin account created.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Admin account created successfully. Save this token now, or log in normally afterwards via /api/v1/auth/login.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'user_id', type: 'integer', example: 1),
                                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@example.com'),
                                new OA\Property(property: 'token', type: 'string', example: '1|abcdefghijklmnopqrstuvwxyz0123456789'),
                                new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(
                response: 403,
                description: 'Setup disabled (no ADMIN_SETUP_SECRET configured) or invalid secret.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'Invalid setup secret.'),
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'The email has already been taken.'),
                        new OA\Property(property: 'errors', type: 'object', additionalProperties: true),
                    ],
                ),
            ),
            new OA\Response(response: 429, description: 'Too many requests (5 per minute).'),
        ],
    )]
    public function setupCreateAdmin(): void {}

    #[OA\Post(
        path: '/v1/setup/create-mentor',
        operationId: 'setupCreateMentor',
        tags: ['Setup'],
        summary: 'Create or promote a mentor account (bootstrap)',
        description: 'Bootstrap endpoint gated by the MENTOR_SETUP_SECRET environment value supplied as '
            .'the `secret` body field — NOT Sanctum, and public. A missing email creates a new active, '
            .'email-verified account; a `pending` account is activated; an `active` account is promoted. '
            .'Soft-deleted / suspended / deleted accounts are rejected with 422. The user\'s '
            .'ProfessionalProfile is set to type=mentor with verification_status=verified. If `password` '
            .'is supplied it always replaces the existing one (admin password-reset path); when omitted, '
            .'an existing password is left unchanged, while a newly created account gets a generated '
            .'password echoed back once. Throttled to 10 requests per minute.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['secret', 'email'],
                properties: [
                    new OA\Property(property: 'secret', type: 'string', description: 'Shared MENTOR_SETUP_SECRET value.', example: 'your-mentor-setup-secret'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 255, example: 'mentor@example.com'),
                    new OA\Property(property: 'name', type: 'string', nullable: true, maxLength: 255, example: 'Mona Mentor'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', nullable: true, minLength: 8, example: 'StrongPass123'),
                    new OA\Property(property: 'expertise', type: 'string', nullable: true, maxLength: 2000, example: 'Backend engineering, system design'),
                    new OA\Property(property: 'affiliation', type: 'string', nullable: true, maxLength: 255, example: 'Acme University'),
                    new OA\Property(property: 'availability', type: 'string', nullable: true, maxLength: 100, example: 'weekends'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Mentor account created and/or promoted and verified.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Mentor profile created successfully. This user can now access the mentor endpoints.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'user_id', type: 'integer', example: 7),
                                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'mentor@example.com'),
                                new OA\Property(property: 'name', type: 'string', example: 'Mona Mentor'),
                                new OA\Property(property: 'registered', type: 'boolean', example: false),
                                new OA\Property(property: 'password_reset', type: 'boolean', example: false),
                                new OA\Property(property: 'status', type: 'string', example: 'active'),
                                new OA\Property(property: 'generated_password', type: 'string', nullable: true, description: 'Only populated when the password was minted by the server.', example: null),
                                new OA\Property(property: 'professional_profile_id', type: 'integer', example: 3),
                                new OA\Property(property: 'type', type: 'string', example: 'mentor'),
                                new OA\Property(property: 'verification_status', type: 'string', example: 'verified'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(
                response: 403,
                description: 'Setup disabled (no MENTOR_SETUP_SECRET configured) or invalid secret.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'Invalid setup secret.'),
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error, or the existing account is suspended/deleted.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'An account with this email already exists and its status is suspended. Restore or reactivate it before promoting it to mentor.'),
                        new OA\Property(property: 'errors', type: 'object', additionalProperties: true),
                    ],
                ),
            ),
            new OA\Response(response: 429, description: 'Too many requests (10 per minute).'),
        ],
    )]
    public function setupCreateMentor(): void {}

    #[OA\Get(
        path: '/v1/internal/baseline-items',
        operationId: 'internalBaselineItems',
        tags: ['Internal'],
        summary: 'Fetch baseline assessment items (server-to-server)',
        description: 'Server-to-server endpoint consumed by the Data Science FastAPI service. The backend '
            .'is the source of truth for assessment content, so this returns each active item together '
            .'with its skill mapping and correct answer. Authenticated with the shared-secret header '
            .'`X-Internal-Secret` (NOT Sanctum). Throttled to 60 requests per minute.',
        parameters: [
            new OA\Parameter(name: 'X-Internal-Secret', in: 'header', required: true, description: 'Shared internal secret. Compared in constant time.', schema: new OA\Schema(type: 'string', example: 'your-internal-secret')),
            new OA\Parameter(name: 'version', in: 'query', required: true, description: 'Assessment version to fetch.', schema: new OA\Schema(type: 'string', example: 'v1.0')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Baseline items for the requested version.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'assessment_version', type: 'string', example: 'v1.0'),
                        new OA\Property(property: 'weight_scale', type: 'string', example: '0..1'),
                        new OA\Property(
                            property: 'items',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'item_id', type: 'string', example: 'BQ-001'),
                                    new OA\Property(property: 'item_type', type: 'string', example: 'multiple_choice'),
                                    new OA\Property(property: 'question_text', type: 'string', example: 'Which HTTP status code means "Not Found"?'),
                                    new OA\Property(property: 'options', type: 'array', nullable: true, items: new OA\Items(type: 'string'), example: ['200', '404', '500']),
                                    new OA\Property(property: 'correct_answer', type: 'string', nullable: true, example: '404'),
                                    new OA\Property(property: 'scoring_rule', type: 'string', nullable: true, example: 'exact_match'),
                                    new OA\Property(property: 'skill_id', type: 'integer', nullable: true, example: 12),
                                    new OA\Property(property: 'skill_slug', type: 'string', nullable: true, example: 'http-basics'),
                                    new OA\Property(property: 'weight', type: 'number', format: 'float', example: 0.5),
                                ],
                            ),
                        ),
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Missing or invalid X-Internal-Secret.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'Unauthorized.'),
                    ],
                ),
            ),
            new OA\Response(response: 422, description: 'Validation error (missing version).', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Too many requests (60 per minute).'),
        ],
    )]
    public function internalBaselineItems(): void {}
}
