<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI documentation for the US-MATCH-02 recommendation / application
 * endpoints:
 *
 *  - App\Http\Controllers\Api\RecommendationController
 *  - App\Http\Controllers\Api\RecommendationFeedbackController
 *  - App\Http\Controllers\Api\ApplicationController
 *
 * Paths are relative to the `/api` server, so they carry the `/v1` prefix
 * exactly as registered in routes/api.php.
 *
 * The learner endpoints sit behind `auth:sanctum`, `account.active` and
 * `role:learner`. The project-owner endpoints (list a project's applications,
 * decide) are behind `auth:sanctum` and `account.active` only — ownership is
 * enforced in ApplicationService, so no role check is documented for them.
 * Every failure uses the shared `code` / `message` / `request_id` envelope.
 */
class RecommendationApplicationEndpoints
{
    #[OA\Get(
        path: '/v1/recommendations',
        operationId: 'recommendationsIndex',
        tags: ['Recommendations'],
        summary: 'List the learner\'s stored project recommendations',
        description: 'Returns the authenticated learner\'s own stored project recommendations '
            .'(scoped by user_id and type `project`), newest first. Paginated: `per_page` defaults '
            .'to 15 and is clamped to 100. Recommendations whose project is no longer accessible are '
            .'still returned as historical records, but RecommendationResource redacts every '
            .'result-derived field and sets `access_revoked` to true.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number.', schema: new OA\Schema(type: 'integer', minimum: 1, example: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Page size (default 15, clamped to 100).', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, example: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Recommendations retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Project recommendations retrieved successfully.'),
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Recommendation')),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 3),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'total', type: 'integer', example: 42),
                            ],
                        ),
                        new OA\Property(property: 'request_id', type: 'string', example: '3f2504e0-4f89-11d3-9a0c-0305e82c3301'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function recommendationsIndex(): void {}

    #[OA\Get(
        path: '/v1/projects/{project}/recommendation',
        operationId: 'recommendationsShowForProject',
        tags: ['Recommendations'],
        summary: 'Get the learner\'s latest stored recommendation for a project',
        description: 'Returns the most recent stored recommendation for the given project. Project '
            .'access is checked first; a nonexistent or inaccessible project and a missing '
            .'recommendation both answer 404 so the endpoint cannot be used to probe projects.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'project', in: 'path', required: true, description: 'Project id.', schema: new OA\Schema(type: 'integer', example: 128)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Recommendation retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Project recommendation retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'project_id', type: 'integer', example: 128),
                                new OA\Property(property: 'score', type: 'number', format: 'float', nullable: true, example: 0.87),
                                new OA\Property(property: 'reasons', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
                                new OA\Property(property: 'limiting_factors', type: 'array', nullable: true, items: new OA\Items(type: 'object', additionalProperties: true)),
                                new OA\Property(property: 'algorithm_version', type: 'string', nullable: true, example: 'matching-v2'),
                                new OA\Property(property: 'configuration_version', type: 'string', nullable: true, example: 'config-2026.09'),
                            ],
                        ),
                        new OA\Property(property: 'request_id', type: 'string', example: '3f2504e0-4f89-11d3-9a0c-0305e82c3301'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Project not found/inaccessible (code `PROJECT_NOT_FOUND`) or no stored recommendation for this project (code `RECOMMENDATION_NOT_FOUND`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function recommendationsShowForProject(): void {}

    #[OA\Post(
        path: '/v1/recommendations/{recommendation}/feedback',
        operationId: 'recommendationFeedbackStore',
        tags: ['Recommendations'],
        summary: 'Record feedback on the learner\'s own recommendation',
        description: 'Records save / hide / decline feedback for a recommendation the learner owns. '
            .'The learner-facing event names map onto stored feedback_events.event_type values: '
            .'`save` → `save`, `hide` → `hide`, `decline` → `reject`. Only the learner who owns the '
            .'recommendation may submit feedback. No suppression window is applied — only the event '
            .'is recorded.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'recommendation', in: 'path', required: true, description: 'Recommendation id.', schema: new OA\Schema(type: 'integer', example: 5012)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['event_type'],
                properties: [
                    new OA\Property(property: 'event_type', type: 'string', enum: ['save', 'hide', 'decline'], description: 'Learner-facing feedback event.', example: 'save'),
                    new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 2000, example: 'Looks like a great fit for my skills.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Feedback recorded.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Recommendation feedback recorded successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 9001),
                                new OA\Property(property: 'recommendation_id', type: 'integer', example: 5012),
                                new OA\Property(property: 'event_type', type: 'string', description: 'The stored feedback_events.event_type.', example: 'save'),
                                new OA\Property(property: 'reason', type: 'string', nullable: true, example: 'Looks like a great fit for my skills.'),
                                new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', nullable: true, example: '2026-10-01T12:34:56+00:00'),
                            ],
                        ),
                        new OA\Property(property: 'request_id', type: 'string', example: '3f2504e0-4f89-11d3-9a0c-0305e82c3301'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'The recommendation belongs to another learner (code `RECOMMENDATION_NOT_OWNED`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Recommendation not found (code `RECOMMENDATION_NOT_FOUND`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error, or unsupported event type (code `RECOMMENDATION_FEEDBACK_UNSUPPORTED`).', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function recommendationFeedbackStore(): void {}

    #[OA\Post(
        path: '/v1/projects/{project}/applications',
        operationId: 'applicationsStore',
        tags: ['Applications'],
        summary: 'Submit an application to a project',
        description: 'Submits the authenticated learner\'s application to a project. The learner must '
            .'have a student profile. Role / eligibility / capacity questions are answered by '
            .'ApplicationService, not by validation, so `project_role_id` and `recommendation_id` are '
            .'scoped through the project and the learner. Replaying the same `idempotency_key` returns '
            .'the original application with 200 instead of creating a second one.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'project', in: 'path', required: true, description: 'Project id.', schema: new OA\Schema(type: 'integer', example: 128)),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'project_role_id', type: 'integer', nullable: true, description: 'Optional role within the project.', example: 7),
                    new OA\Property(property: 'application_data', type: 'object', nullable: true, additionalProperties: true, description: 'Structured answers, not a string.', example: ['motivation' => 'I want to build production systems.', 'availability' => '20h/week']),
                    new OA\Property(property: 'recommendation_id', type: 'integer', nullable: true, description: 'The recommendation this application is based on.', example: 5012),
                    new OA\Property(property: 'idempotency_key', type: 'string', nullable: true, maxLength: 191, description: 'Client-supplied key that makes a replay return the original application.', example: 'apply-128-learner-42-0001'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Application submitted.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Application submitted successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Application'),
                        new OA\Property(property: 'request_id', type: 'string', example: '3f2504e0-4f89-11d3-9a0c-0305e82c3301'),
                    ],
                ),
            ),
            new OA\Response(
                response: 200,
                description: 'Idempotent replay: the existing application is returned.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Application already submitted; returning the existing application.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Application'),
                        new OA\Property(property: 'request_id', type: 'string', example: '3f2504e0-4f89-11d3-9a0c-0305e82c3301'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Project not found (code `PROJECT_NOT_FOUND`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error (code `VALIDATION_ERROR`) or the learner has no student profile (code `STUDENT_PROFILE_NOT_FOUND`).', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function applicationsStore(): void {}

    #[OA\Get(
        path: '/v1/applications',
        operationId: 'applicationsIndex',
        tags: ['Applications'],
        summary: 'List the learner\'s own applications',
        description: 'Returns the authenticated learner\'s own applications, optionally filtered by '
            .'status. Paginated: `per_page` defaults to 15 and is clamped to 100.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by application status.', schema: new OA\Schema(type: 'string', enum: ['submitted', 'shortlisted', 'accepted', 'rejected', 'waitlisted', 'withdrawn'], example: 'submitted')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number.', schema: new OA\Schema(type: 'integer', minimum: 1, example: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Page size (default 15, clamped to 100).', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, example: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Applications retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Applications retrieved successfully.'),
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Application')),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 2),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'total', type: 'integer', example: 18),
                            ],
                        ),
                        new OA\Property(property: 'request_id', type: 'string', example: '3f2504e0-4f89-11d3-9a0c-0305e82c3301'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid `status` filter (code `VALIDATION_ERROR`).', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function applicationsIndex(): void {}

    #[OA\Post(
        path: '/v1/applications/{application}/withdraw',
        operationId: 'applicationsWithdraw',
        tags: ['Applications'],
        summary: 'Withdraw one of the learner\'s own applications',
        description: 'Withdraws an application owned by the authenticated learner. Ownership and the '
            .'legality of the transition are enforced in ApplicationService. An optional `reason` is '
            .'stored with the withdrawal.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'application', in: 'path', required: true, description: 'Application id.', schema: new OA\Schema(type: 'integer', example: 3320)),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'reason', type: 'string', nullable: true, description: 'Optional withdrawal reason.', example: 'I accepted another opportunity.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Application withdrawn.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Application withdrawn successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Application'),
                        new OA\Property(property: 'request_id', type: 'string', example: '3f2504e0-4f89-11d3-9a0c-0305e82c3301'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'The application belongs to another learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Application not found (code `APPLICATION_NOT_FOUND`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'The application cannot be withdrawn from its current status.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function applicationsWithdraw(): void {}

    #[OA\Get(
        path: '/v1/projects/{project}/applications',
        operationId: 'applicationsIndexForProject',
        tags: ['Applications'],
        summary: 'List a project\'s applications (project owner)',
        description: 'Returns the applications submitted to a project. This endpoint is not '
            .'role-gated: any authenticated user may call it, and project ownership is enforced in '
            .'ApplicationService, so a non-owner receives 403. Paginated: `per_page` defaults to 15 '
            .'and is clamped to 100.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'project', in: 'path', required: true, description: 'Project id.', schema: new OA\Schema(type: 'integer', example: 128)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number.', schema: new OA\Schema(type: 'integer', minimum: 1, example: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Page size (default 15, clamped to 100).', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, example: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Project applications retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Project applications retrieved successfully.'),
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Application')),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 1),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'total', type: 'integer', example: 6),
                            ],
                        ),
                        new OA\Property(property: 'request_id', type: 'string', example: '3f2504e0-4f89-11d3-9a0c-0305e82c3301'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'The authenticated user does not own the project.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Project not found (code `PROJECT_NOT_FOUND`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function applicationsIndexForProject(): void {}

    #[OA\Patch(
        path: '/v1/projects/{project}/applications/{application}',
        operationId: 'applicationsDecide',
        tags: ['Applications'],
        summary: 'Record the project owner\'s decision on an application',
        description: 'Applies the project owner\'s decision to an application. `status` must be one of '
            .'the transitions the Application model allows from the current state. The application '
            .'must belong to the project in the URL. Project ownership is enforced in '
            .'ApplicationService, so a non-owner receives 403.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'project', in: 'path', required: true, description: 'Project id.', schema: new OA\Schema(type: 'integer', example: 128)),
            new OA\Parameter(name: 'application', in: 'path', required: true, description: 'Application id.', schema: new OA\Schema(type: 'integer', example: 3320)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['shortlisted', 'accepted', 'rejected', 'waitlisted'], description: 'Target decision status.', example: 'shortlisted'),
                    new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 2000, example: 'Strong portfolio and relevant skills.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Application status updated.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Application status updated successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Application'),
                        new OA\Property(property: 'request_id', type: 'string', example: '3f2504e0-4f89-11d3-9a0c-0305e82c3301'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'The authenticated user does not own the project.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Application not found (code `APPLICATION_NOT_FOUND`) or it does not belong to the project (code `APPLICATION_PROJECT_MISMATCH`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error, or a status transition the application does not allow (code `VALIDATION_ERROR`).', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function applicationsDecide(): void {}
}
