<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI documentation for the project endpoints. Two controllers share the
 * `/v1/projects` path space:
 *
 *  - App\Http\Controllers\Api\ProjectController          — learner-facing catalog (index, show)
 *  - App\Http\Controllers\Api\ProjectManagementController — owner/admin lifecycle (store, update, submit, open, approve, request-changes, reject)
 *  - App\Http\Controllers\Api\ProjectMatchingController   — project matching (match)
 *
 * Paths are relative to the `/api` server, so they carry the `/v1` prefix
 * exactly as registered in routes/api.php.
 */
class ProjectEndpoints
{
    #[OA\Get(
        path: '/v1/projects',
        operationId: 'projectsIndex',
        tags: ['Projects'],
        summary: 'List the accessible project catalog for the authenticated learner',
        description: 'Returns the paginated catalog of projects the authenticated learner is authorized to '
            .'see, applying the shared access and availability rules (only `open`, non-expired, '
            .'non-confidentiality-blocked projects). The catalog is ordered by `created_at` descending, '
            .'then `id` descending, so pagination is deterministic. A learner without a student profile '
            .'receives 422. Protected by auth:sanctum + account.active + role:learner.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'search',
                in: 'query',
                required: false,
                description: 'Keyword search across title, description and objectives (LIKE match, max 100 chars).',
                schema: new OA\Schema(type: 'string', maxLength: 100, example: 'e-commerce'),
            ),
            new OA\Parameter(
                name: 'type',
                in: 'query',
                required: false,
                description: 'Exact-match filter on the project type.',
                schema: new OA\Schema(type: 'string', enum: ['simulation', 'company_sponsored'], example: 'company_sponsored'),
            ),
            new OA\Parameter(
                name: 'domain',
                in: 'query',
                required: false,
                description: 'Exact-match filter on the project domain (max 100 chars).',
                schema: new OA\Schema(type: 'string', maxLength: 100, example: 'web_development'),
            ),
            new OA\Parameter(
                name: 'work_mode',
                in: 'query',
                required: false,
                description: 'Exact-match filter on the work mode (max 50 chars).',
                schema: new OA\Schema(type: 'string', maxLength: 50, example: 'remote'),
            ),
            new OA\Parameter(
                name: 'difficulty',
                in: 'query',
                required: false,
                description: 'Exact-match filter on the difficulty level.',
                schema: new OA\Schema(type: 'number', format: 'float', minimum: 0, maximum: 5, example: 3),
            ),
            new OA\Parameter(
                name: 'organization_id',
                in: 'query',
                required: false,
                description: 'Filter to projects belonging to this organization (must exist).',
                schema: new OA\Schema(type: 'integer', example: 12),
            ),
            new OA\Parameter(
                name: 'skill_ids',
                in: 'query',
                required: false,
                description: 'Repeatable set of skill ids. The project must require ALL of the supplied skills. '
                    .'Duplicates are collapsed, so a repeated id simply means "that one skill".',
                style: 'form',
                explode: true,
                schema: new OA\Schema(
                    type: 'array',
                    items: new OA\Items(type: 'integer', example: 15),
                ),
            ),
            new OA\Parameter(
                name: 'minimum_level',
                in: 'query',
                required: false,
                description: 'Per-skill minimum level floor for the required-skills filter. Only meaningful '
                    .'together with `skill_ids`; sending it without `skill_ids` returns 422.',
                schema: new OA\Schema(type: 'number', format: 'float', minimum: 0, maximum: 5, example: 3),
            ),
            new OA\Parameter(
                name: 'page',
                in: 'query',
                required: false,
                description: 'Page number (1-based).',
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1),
            ),
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                required: false,
                description: 'Items per page (1–50). Defaults to 50.',
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 50, example: 20),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Accessible projects retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Accessible projects retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Project'),
                        ),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 4),
                                new OA\Property(property: 'per_page', type: 'integer', example: 20),
                                new OA\Property(property: 'total', type: 'integer', example: 73),
                            ],
                        ),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f1c9e2a-8b7d-4c1e-9f2a-1d2e3f4a5b6c'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated account is not a learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 422,
                description: 'Filter validation failed, or the authenticated learner has no student profile '
                    .'(code STUDENT_PROFILE_NOT_FOUND).',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
        ],
    )]
    public function projectsIndex(): void {}

    #[OA\Get(
        path: '/v1/projects/{project}',
        operationId: 'projectsShow',
        tags: ['Projects'],
        summary: 'Retrieve a single accessible project with per-learner details',
        description: 'Returns one project the authenticated learner is authorized to see. The same access and '
            .'availability rules as the catalog are applied, so a learner cannot bypass catalog restrictions by '
            .'supplying a project id directly. This is the only endpoint that attaches the per-learner '
            .'`eligibility` and `capacity_state` blocks (computed by ProjectEligibilityService and '
            .'ProjectCapacityPolicy respectively). A learner without a student profile receives 422. Protected '
            .'by auth:sanctum + account.active + role:learner.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'project',
                in: 'path',
                required: true,
                description: 'Project id.',
                schema: new OA\Schema(type: 'integer', example: 55),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Project details retrieved, including the per-learner eligibility and capacity blocks.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Project details retrieved successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Project'),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f1c9e2a-8b7d-4c1e-9f2a-1d2e3f4a5b6c'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'The project exists but is not available to this learner (code PROJECT_UNAUTHORIZED).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 404,
                description: 'The requested project does not exist (code PROJECT_NOT_FOUND).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 422,
                description: 'The authenticated learner has no student profile (code STUDENT_PROFILE_NOT_FOUND).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function projectsShow(): void {}

    #[OA\Post(
        path: '/v1/projects',
        operationId: 'projectsStore',
        tags: ['Projects'],
        summary: 'Create a project (starts as a draft)',
        description: 'Creates a project owned by the caller or by a named company representative. The project '
            .'always starts as `draft`. Shape and ranges are validated here; who may create, which organization '
            .'it belongs to and who owns it are decided by ProjectLifecycleService (platform admin, or a '
            .'company_admin / university_admin of an approved company for company_sponsored projects). '
            .'`organization_id` and `owner_id` are only honoured when a platform administrator creates a '
            .'company_sponsored project on behalf of a company; otherwise they are ignored. Date ordering '
            .'(end >= start, deadline <= start) is validated by the service against the final state. Protected '
            .'by auth:sanctum + account.active.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'title'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['simulation', 'company_sponsored'], example: 'company_sponsored'),
                    new OA\Property(property: 'domain', type: 'string', nullable: true, maxLength: 100, example: 'web_development'),
                    new OA\Property(property: 'title', type: 'string', maxLength: 255, example: 'E-commerce API Modernisation'),
                    new OA\Property(property: 'description', type: 'string', nullable: true, maxLength: 5000, example: 'Modernise the checkout API of a legacy e-commerce platform.'),
                    new OA\Property(property: 'objectives', type: 'string', nullable: true, maxLength: 5000, example: 'Ship a documented, tested v2 checkout API.'),
                    new OA\Property(
                        property: 'learning_outcomes',
                        type: 'array',
                        nullable: true,
                        maxItems: 50,
                        items: new OA\Items(type: 'string', maxLength: 500),
                        example: ['API versioning', 'Test-driven development'],
                    ),
                    new OA\Property(property: 'difficulty', type: 'number', format: 'float', nullable: true, minimum: 0, maximum: 5, example: 3),
                    new OA\Property(property: 'work_mode', type: 'string', nullable: true, maxLength: 50, example: 'remote'),
                    new OA\Property(property: 'role', type: 'string', nullable: true, maxLength: 255, example: 'Backend Developer'),
                    new OA\Property(property: 'schedule', type: 'string', nullable: true, maxLength: 255, example: '20h/week'),
                    new OA\Property(property: 'capacity', type: 'integer', nullable: true, minimum: 1, maximum: 1000, example: 5),
                    new OA\Property(property: 'min_team_size', type: 'integer', nullable: true, minimum: 1, maximum: 1000, example: 2),
                    new OA\Property(property: 'start_date', type: 'string', format: 'date', nullable: true, example: '2026-02-01'),
                    new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true, example: '2026-05-01'),
                    new OA\Property(property: 'application_deadline', type: 'string', format: 'date', nullable: true, example: '2026-01-20'),
                    new OA\Property(property: 'confidentiality', type: 'string', nullable: true, enum: ['public', 'restricted'], example: 'public'),
                    new OA\Property(property: 'organization_id', type: 'integer', nullable: true, example: 12, description: 'Must exist in organizations.id. Only honoured for a platform administrator creating a company_sponsored project.'),
                    new OA\Property(property: 'owner_id', type: 'integer', nullable: true, example: 88, description: 'Must exist in users.id. Only honoured for a platform administrator creating a company_sponsored project; the owner must be an active administrator of the sponsoring organization.'),
                    new OA\Property(
                        property: 'required_skills',
                        type: 'array',
                        nullable: true,
                        maxItems: 50,
                        items: new OA\Items(
                            type: 'object',
                            required: ['skill_id'],
                            properties: [
                                new OA\Property(property: 'skill_id', type: 'integer', example: 15, description: 'Must exist in skills.id; must be distinct across entries.'),
                                new OA\Property(property: 'minimum_level', type: 'number', format: 'float', minimum: 0, maximum: 5, example: 3),
                                new OA\Property(property: 'is_critical_entry', type: 'boolean', example: true),
                            ],
                        ),
                    ),
                    new OA\Property(
                        property: 'roles',
                        type: 'array',
                        nullable: true,
                        maxItems: 50,
                        items: new OA\Items(
                            type: 'object',
                            required: ['title'],
                            properties: [
                                new OA\Property(property: 'title', type: 'string', maxLength: 255, example: 'Backend Developer', description: 'Must be distinct across entries.'),
                                new OA\Property(property: 'description', type: 'string', nullable: true, maxLength: 1000, example: 'Owns the API layer.'),
                                new OA\Property(property: 'is_active', type: 'boolean', example: true),
                            ],
                        ),
                    ),
                    new OA\Property(
                        property: 'eligibility_constraints',
                        type: 'array',
                        nullable: true,
                        maxItems: 50,
                        items: new OA\Items(
                            type: 'object',
                            required: ['constraint_type', 'value'],
                            properties: [
                                new OA\Property(property: 'constraint_type', type: 'string', enum: ['location', 'language', 'schedule', 'work_mode'], example: 'location'),
                                new OA\Property(property: 'value', type: 'string', maxLength: 255, example: 'Jordan'),
                            ],
                        ),
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Project created (status `draft`).',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Project created successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Project'),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f1c9e2a-8b7d-4c1e-9f2a-1d2e3f4a5b6c'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'The caller may not create projects (code PROJECT_CREATE_FORBIDDEN), does not '
                    .'administer an active organization (code PROJECT_ORGANIZATION_REQUIRED), or does not '
                    .'administer the named organization (code PROJECT_ORGANIZATION_NOT_ADMINISTERED).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error, or a business-rule failure such as PROJECT_TYPE_INVALID, '
                    .'PROJECT_ORGANIZATION_REQUIRED, PROJECT_OWNER_REQUIRED, PROJECT_OWNER_INVALID, '
                    .'PROJECT_OWNER_MUST_BE_COMPANY_REPRESENTATIVE, PROJECT_ORGANIZATION_NOT_FOUND, '
                    .'PROJECT_ORGANIZATION_NOT_A_COMPANY, PROJECT_ORGANIZATION_NOT_VERIFIED, '
                    .'PROJECT_OWNER_NOT_ORGANIZATION_REPRESENTATIVE or PROJECT_INVALID_DATES.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
            new OA\Response(
                response: 500,
                description: 'Unexpected server error (code PROJECT_REQUEST_FAILED).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function projectsStore(): void {}

    #[OA\Patch(
        path: '/v1/projects/{project}',
        operationId: 'projectsUpdate',
        tags: ['Projects'],
        summary: 'Update a project (draft or changes_requested only)',
        description: 'Partially updates a project the caller manages. Every top-level field is optional, so an '
            .'omitted field is left untouched; supplying a child set (required_skills, roles, '
            .'eligibility_constraints) replaces it wholesale. Only a project in a draft or '
            .'changes_requested state may be edited. `type`, `organization_id` and `owner_id` are only '
            .'re-evaluated when the type actually changes. The `version` column is bumped only when a '
            .'matching-relevant field changed. Authorization (owner or platform admin) is enforced by '
            .'ProjectLifecycleService. Protected by auth:sanctum + account.active.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'project',
                in: 'path',
                required: true,
                description: 'Project id.',
                schema: new OA\Schema(type: 'integer', example: 55),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['simulation', 'company_sponsored'], example: 'company_sponsored'),
                    new OA\Property(property: 'domain', type: 'string', nullable: true, maxLength: 100, example: 'web_development'),
                    new OA\Property(property: 'title', type: 'string', maxLength: 255, example: 'E-commerce API Modernisation (v2)'),
                    new OA\Property(property: 'description', type: 'string', nullable: true, maxLength: 5000),
                    new OA\Property(property: 'objectives', type: 'string', nullable: true, maxLength: 5000),
                    new OA\Property(
                        property: 'learning_outcomes',
                        type: 'array',
                        nullable: true,
                        maxItems: 50,
                        items: new OA\Items(type: 'string', maxLength: 500),
                    ),
                    new OA\Property(property: 'difficulty', type: 'number', format: 'float', nullable: true, minimum: 0, maximum: 5, example: 4),
                    new OA\Property(property: 'work_mode', type: 'string', nullable: true, maxLength: 50, example: 'hybrid'),
                    new OA\Property(property: 'role', type: 'string', nullable: true, maxLength: 255),
                    new OA\Property(property: 'schedule', type: 'string', nullable: true, maxLength: 255),
                    new OA\Property(property: 'capacity', type: 'integer', nullable: true, minimum: 1, maximum: 1000, example: 8),
                    new OA\Property(property: 'min_team_size', type: 'integer', nullable: true, minimum: 1, maximum: 1000),
                    new OA\Property(property: 'start_date', type: 'string', format: 'date', nullable: true, example: '2026-02-01'),
                    new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true, example: '2026-05-01'),
                    new OA\Property(property: 'application_deadline', type: 'string', format: 'date', nullable: true, example: '2026-01-20'),
                    new OA\Property(property: 'confidentiality', type: 'string', nullable: true, enum: ['public', 'restricted'], example: 'restricted'),
                    new OA\Property(property: 'organization_id', type: 'integer', nullable: true, example: 12, description: 'Only honoured when the type changes and a platform administrator updates a company_sponsored project.'),
                    new OA\Property(property: 'owner_id', type: 'integer', nullable: true, example: 88, description: 'Only honoured when the type changes.'),
                    new OA\Property(
                        property: 'required_skills',
                        type: 'array',
                        nullable: true,
                        maxItems: 50,
                        items: new OA\Items(
                            type: 'object',
                            required: ['skill_id'],
                            properties: [
                                new OA\Property(property: 'skill_id', type: 'integer', example: 15, description: 'Must exist in skills.id; must be distinct across entries.'),
                                new OA\Property(property: 'minimum_level', type: 'number', format: 'float', minimum: 0, maximum: 5, example: 3),
                                new OA\Property(property: 'is_critical_entry', type: 'boolean', example: false),
                            ],
                        ),
                    ),
                    new OA\Property(
                        property: 'roles',
                        type: 'array',
                        nullable: true,
                        maxItems: 50,
                        items: new OA\Items(
                            type: 'object',
                            required: ['title'],
                            properties: [
                                new OA\Property(property: 'title', type: 'string', maxLength: 255, example: 'Backend Developer'),
                                new OA\Property(property: 'description', type: 'string', nullable: true, maxLength: 1000),
                                new OA\Property(property: 'is_active', type: 'boolean', example: true),
                            ],
                        ),
                    ),
                    new OA\Property(
                        property: 'eligibility_constraints',
                        type: 'array',
                        nullable: true,
                        maxItems: 50,
                        items: new OA\Items(
                            type: 'object',
                            required: ['constraint_type', 'value'],
                            properties: [
                                new OA\Property(property: 'constraint_type', type: 'string', enum: ['location', 'language', 'schedule', 'work_mode'], example: 'language'),
                                new OA\Property(property: 'value', type: 'string', maxLength: 255, example: 'English'),
                            ],
                        ),
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Project updated.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Project updated successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Project'),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f1c9e2a-8b7d-4c1e-9f2a-1d2e3f4a5b6c'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'The caller is not the project owner (code PROJECT_NOT_OWNED) or is not allowed to '
                    .'link the named organization (code PROJECT_ORGANIZATION_NOT_ADMINISTERED / '
                    .'PROJECT_ORGANIZATION_REQUIRED).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 404,
                description: 'The requested project does not exist (code PROJECT_NOT_FOUND).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error, or a business-rule failure such as PROJECT_NOT_EDITABLE, '
                    .'PROJECT_INVALID_DATES or one of the ownership codes returned by creation.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
            new OA\Response(
                response: 500,
                description: 'Unexpected server error (code PROJECT_REQUEST_FAILED).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function projectsUpdate(): void {}

    #[OA\Post(
        path: '/v1/projects/{project}/submit',
        operationId: 'projectsSubmit',
        tags: ['Projects'],
        summary: 'Submit a project for review',
        description: 'Moves a project from draft or changes_requested to submitted. The project must be '
            .'complete: when required fields are missing the status is left untouched and every missing piece '
            .'is reported at once (code PROJECT_INCOMPLETE). Takes no request body. Authorization (owner or '
            .'platform admin) is enforced by ProjectLifecycleService. Protected by auth:sanctum + account.active.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'project',
                in: 'path',
                required: true,
                description: 'Project id.',
                schema: new OA\Schema(type: 'integer', example: 55),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Project submitted for review.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Project submitted for review.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Project'),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f1c9e2a-8b7d-4c1e-9f2a-1d2e3f4a5b6c'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'The caller is not the project owner or a platform administrator (code PROJECT_NOT_OWNED).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 404,
                description: 'The requested project does not exist (code PROJECT_NOT_FOUND).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 409,
                description: 'The project status changed while the request was being processed (code PROJECT_STATUS_CONFLICT).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 422,
                description: 'The transition is not allowed (code PROJECT_INVALID_TRANSITION) or the project is '
                    .'incomplete (code PROJECT_INCOMPLETE).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 500,
                description: 'Unexpected server error (code PROJECT_REQUEST_FAILED).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function projectsSubmit(): void {}

    #[OA\Post(
        path: '/v1/projects/{project}/open',
        operationId: 'projectsOpen',
        tags: ['Projects'],
        summary: 'Open an approved project',
        description: 'Moves a project from approved to open. This is the step that makes a project discoverable '
            .'and lets matching and applications operate. Takes no request body. Authorization (owner or '
            .'platform admin) is enforced by ProjectLifecycleService. Protected by auth:sanctum + account.active.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'project',
                in: 'path',
                required: true,
                description: 'Project id.',
                schema: new OA\Schema(type: 'integer', example: 55),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Project opened successfully.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Project opened successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Project'),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f1c9e2a-8b7d-4c1e-9f2a-1d2e3f4a5b6c'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'The caller is not the project owner or a platform administrator (code PROJECT_NOT_OWNED).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 404,
                description: 'The requested project does not exist (code PROJECT_NOT_FOUND).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 409,
                description: 'The project status changed while the request was being processed (code PROJECT_STATUS_CONFLICT).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 422,
                description: 'The transition is not allowed (code PROJECT_INVALID_TRANSITION).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 500,
                description: 'Unexpected server error (code PROJECT_REQUEST_FAILED).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function projectsOpen(): void {}

    #[OA\Post(
        path: '/v1/projects/{project}/approve',
        operationId: 'projectsApprove',
        tags: ['Projects'],
        summary: 'Approve a submitted project (platform administrator only)',
        description: 'Moves a project from submitted to approved. The project becomes `approved`, not `active`, '
            .'and is not opened automatically. An optional `reason` is recorded in the audit trail. The caller '
            .'must be a platform administrator who is not the project owner. Protected by auth:sanctum + '
            .'account.active + admin, with the review separation re-checked by ProjectLifecycleService.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'project',
                in: 'path',
                required: true,
                description: 'Project id.',
                schema: new OA\Schema(type: 'integer', example: 55),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 1000, example: 'Meets all review criteria.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Project approved successfully.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Project approved successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Project'),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f1c9e2a-8b7d-4c1e-9f2a-1d2e3f4a5b6c'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'The caller is not a platform administrator (code PROJECT_REVIEW_FORBIDDEN) or is '
                    .'the project owner reviewing their own project (code PROJECT_REVIEW_SELF_FORBIDDEN).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 404,
                description: 'The requested project does not exist (code PROJECT_NOT_FOUND).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 409,
                description: 'The project status changed while the request was being processed (code PROJECT_STATUS_CONFLICT).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error, the transition is not allowed (code PROJECT_INVALID_TRANSITION) '
                    .'or the project is incomplete (code PROJECT_INCOMPLETE).',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
            new OA\Response(
                response: 500,
                description: 'Unexpected server error (code PROJECT_REQUEST_FAILED).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function projectsApprove(): void {}

    #[OA\Post(
        path: '/v1/projects/{project}/request-changes',
        operationId: 'projectsRequestChanges',
        tags: ['Projects'],
        summary: 'Send a submitted project back for changes (platform administrator only)',
        description: 'Moves a project from submitted to changes_requested. A `reason` is mandatory for this '
            .'decision; when it is missing or blank the service returns 422 with code PROJECT_REASON_REQUIRED. '
            .'The caller must be a platform administrator who is not the project owner. Protected by '
            .'auth:sanctum + account.active + admin.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'project',
                in: 'path',
                required: true,
                description: 'Project id.',
                schema: new OA\Schema(type: 'integer', example: 55),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', maxLength: 1000, example: 'Please add a clear application deadline and at least one required skill.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Changes requested on the project.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Changes requested on the project.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Project'),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f1c9e2a-8b7d-4c1e-9f2a-1d2e3f4a5b6c'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'The caller is not a platform administrator (code PROJECT_REVIEW_FORBIDDEN) or is '
                    .'the project owner (code PROJECT_REVIEW_SELF_FORBIDDEN).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 404,
                description: 'The requested project does not exist (code PROJECT_NOT_FOUND).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 409,
                description: 'The project status changed while the request was being processed (code PROJECT_STATUS_CONFLICT).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error, a missing reason (code PROJECT_REASON_REQUIRED) or a transition '
                    .'that is not allowed (code PROJECT_INVALID_TRANSITION).',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
            new OA\Response(
                response: 500,
                description: 'Unexpected server error (code PROJECT_REQUEST_FAILED).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function projectsRequestChanges(): void {}

    #[OA\Post(
        path: '/v1/projects/{project}/reject',
        operationId: 'projectsReject',
        tags: ['Projects'],
        summary: 'Reject a submitted project (platform administrator only)',
        description: 'Moves a project from submitted to rejected. An optional `reason` is recorded in the audit '
            .'trail. The caller must be a platform administrator who is not the project owner. Protected by '
            .'auth:sanctum + account.active + admin.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'project',
                in: 'path',
                required: true,
                description: 'Project id.',
                schema: new OA\Schema(type: 'integer', example: 55),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 1000, example: 'Duplicate of an existing project.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Project rejected.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Project rejected.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Project'),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f1c9e2a-8b7d-4c1e-9f2a-1d2e3f4a5b6c'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'The caller is not a platform administrator (code PROJECT_REVIEW_FORBIDDEN) or is '
                    .'the project owner (code PROJECT_REVIEW_SELF_FORBIDDEN).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 404,
                description: 'The requested project does not exist (code PROJECT_NOT_FOUND).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 409,
                description: 'The project status changed while the request was being processed (code PROJECT_STATUS_CONFLICT).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error or a transition that is not allowed (code PROJECT_INVALID_TRANSITION).',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
            new OA\Response(
                response: 500,
                description: 'Unexpected server error (code PROJECT_REQUEST_FAILED).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function projectsReject(): void {}

    #[OA\Post(
        path: '/v1/projects/{project}/match',
        operationId: 'projectMatch',
        tags: ['Project Matching'],
        summary: 'Calculate the project matching recommendation for the authenticated learner',
        description: 'Calculates and persists a deterministic matching recommendation for the authenticated '
            .'learner and the given project. It takes no request body: the only input is the route parameter. '
            .'The service validates availability, authorization and eligibility, builds an immutable snapshot, '
            .'calls the Data Science matching service and stores the validated result. The matching integration '
            .'is gated on the DATA_SCIENCE_PROJECT_MATCHING_ENABLED flag: when it is disabled the endpoint '
            .'returns 503 (code INTELLIGENCE_NOT_CONFIGURED) rather than a fabricated result. The top-level '
            .'`request_id` is the caller\'s X-Request-ID, while `data.request_id` is the snapshot correlation '
            .'id sent to the Data Science service. Protected by auth:sanctum + account.active + role:learner.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'project',
                in: 'path',
                required: true,
                description: 'Project id.',
                schema: new OA\Schema(type: 'integer', example: 55),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Matching recommendation calculated.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Project matching recommendation calculated successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/ProjectMatchingResult'),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f1c9e2a-8b7d-4c1e-9f2a-1d2e3f4a5b6c'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'The learner is not authorized to access the project (code PROJECT_MATCH_UNAUTHORIZED).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 404,
                description: 'The requested project does not exist (code PROJECT_NOT_FOUND).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 422,
                description: 'The learner has no student profile (code PROJECT_MATCH_NO_STUDENT_PROFILE), the '
                    .'project is unavailable (code PROJECT_MATCH_UNAVAILABLE), the learner is ineligible '
                    .'(code PROJECT_MATCH_INELIGIBLE) or no active algorithm configuration exists '
                    .'(code PROJECT_MATCH_NO_CONFIG).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 500,
                description: 'Unexpected matching failure (code PROJECT_MATCHING_FAILED).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 503,
                description: 'The project matching integration is disabled (code INTELLIGENCE_NOT_CONFIGURED).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function projectMatch(): void {}
}
