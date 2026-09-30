<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI documentation for the administrator endpoints.
 *
 *  - App\Http\Controllers\Api\Admin\OrganizationController — organization registration review (index, show, downloadProofFile, approve, reject)
 *  - App\Http\Controllers\Api\Admin\ProjectController        — platform-administrator project read + moderation (index, show, store, update, cancel)
 *
 * Every route here is registered under the `auth:sanctum`, `account.active`
 * and `admin` middleware in routes/api.php, so each operation requires a
 * bearer token and an administrator account.
 *
 * Paths are relative to the `/api` server, so they carry the `/v1` prefix
 * exactly as registered in routes/api.php.
 */
class AdminEndpoints
{
    #[OA\Get(
        path: '/v1/admin/organizations',
        operationId: 'adminOrganizationsIndex',
        tags: ['Admin'],
        summary: 'List organization registration requests',
        description: 'Returns organization (company / university / training partner) registration requests, '
            .'newest first, with the reviewing administrator eager-loaded as `verifier`. Optionally filtered '
            .'by verification status; any other status value is ignored rather than rejected. Server-side '
            .'paginated. Protected by auth:sanctum + account.active + admin.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'status',
                in: 'query',
                required: false,
                description: 'Filter by verification status. Values outside the enum are ignored.',
                schema: new OA\Schema(type: 'string', enum: ['pending', 'verified', 'rejected'], example: 'pending'),
            ),
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                required: false,
                description: 'Items per page. Defaults to 15.',
                schema: new OA\Schema(type: 'integer', default: 15, example: 15),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Organizations retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Organizations retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            description: 'Laravel paginator of Organization models.',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(
                                    property: 'data',
                                    type: 'array',
                                    items: new OA\Items(
                                        type: 'object',
                                        properties: [
                                            new OA\Property(property: 'id', type: 'integer', example: 12),
                                            new OA\Property(property: 'name', type: 'string', example: 'Acme Software'),
                                            new OA\Property(property: 'type', type: 'string', example: 'company'),
                                            new OA\Property(property: 'verification_status', type: 'string', example: 'pending'),
                                            new OA\Property(property: 'verified_at', type: 'string', format: 'date-time', nullable: true),
                                            new OA\Property(property: 'verified_by', type: 'integer', nullable: true, example: 3),
                                            new OA\Property(property: 'contact_email', type: 'string', format: 'email', example: 'contact@acme.com'),
                                            new OA\Property(property: 'contact_phone', type: 'string', nullable: true),
                                            new OA\Property(property: 'website', type: 'string', nullable: true),
                                            new OA\Property(property: 'description', type: 'string', nullable: true),
                                            new OA\Property(property: 'industry', type: 'string', nullable: true),
                                            new OA\Property(property: 'company_size', type: 'string', nullable: true),
                                            new OA\Property(property: 'country', type: 'string', nullable: true),
                                            new OA\Property(property: 'city', type: 'string', nullable: true),
                                            new OA\Property(property: 'address', type: 'string', nullable: true),
                                            new OA\Property(property: 'postal_code', type: 'string', nullable: true),
                                            new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
                                            new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
                                            new OA\Property(
                                                property: 'verifier',
                                                type: 'object',
                                                nullable: true,
                                                properties: [
                                                    new OA\Property(property: 'id', type: 'integer'),
                                                    new OA\Property(property: 'name', type: 'string'),
                                                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                                                ],
                                            ),
                                        ],
                                    ),
                                ),
                                new OA\Property(property: 'first_page_url', type: 'string', nullable: true),
                                new OA\Property(property: 'from', type: 'integer', nullable: true, example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 3),
                                new OA\Property(property: 'last_page_url', type: 'string', nullable: true),
                                new OA\Property(property: 'next_page_url', type: 'string', nullable: true),
                                new OA\Property(property: 'path', type: 'string', example: 'http://localhost/api/v1/admin/organizations'),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'prev_page_url', type: 'string', nullable: true),
                                new OA\Property(property: 'to', type: 'integer', nullable: true, example: 15),
                                new OA\Property(property: 'total', type: 'integer', example: 34),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated account is not an administrator.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function adminOrganizationsIndex(): void {}

    #[OA\Get(
        path: '/v1/admin/organizations/{organization}',
        operationId: 'adminOrganizationsShow',
        tags: ['Admin'],
        summary: 'Retrieve one organization registration request',
        description: 'Returns a single organization request, including the reviewer and a link to the uploaded '
            .'proof document so the administrator can review it before deciding. Protected by auth:sanctum + '
            .'account.active + admin.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'organization',
                in: 'path',
                required: true,
                description: 'Organization id.',
                schema: new OA\Schema(type: 'integer', example: 12),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Organization retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Organization retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 12),
                                new OA\Property(property: 'name', type: 'string', example: 'Acme Software'),
                                new OA\Property(property: 'type', type: 'string', example: 'company'),
                                new OA\Property(property: 'verification_status', type: 'string', example: 'pending'),
                                new OA\Property(property: 'verified_at', type: 'string', format: 'date-time', nullable: true),
                                new OA\Property(
                                    property: 'verified_by',
                                    type: 'object',
                                    nullable: true,
                                    description: 'The reviewing administrator, or null while still pending.',
                                    properties: [
                                        new OA\Property(property: 'id', type: 'integer', example: 3),
                                        new OA\Property(property: 'name', type: 'string', example: 'Platform Admin'),
                                        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@skillspan.test'),
                                    ],
                                ),
                                new OA\Property(property: 'contact_email', type: 'string', format: 'email', example: 'contact@acme.com'),
                                new OA\Property(property: 'contact_phone', type: 'string', nullable: true, example: '+962790000001'),
                                new OA\Property(property: 'website', type: 'string', nullable: true, example: 'https://acme.com'),
                                new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Software development company.'),
                                new OA\Property(property: 'industry', type: 'string', nullable: true, example: 'Information Technology'),
                                new OA\Property(property: 'company_size', type: 'string', nullable: true, example: '51-200'),
                                new OA\Property(property: 'country', type: 'string', nullable: true, example: 'Jordan'),
                                new OA\Property(property: 'city', type: 'string', nullable: true, example: 'Amman'),
                                new OA\Property(property: 'address', type: 'string', nullable: true, example: '12 King Hussein St.'),
                                new OA\Property(property: 'postal_code', type: 'string', nullable: true, example: '11118'),
                                new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
                                new OA\Property(
                                    property: 'proof_file',
                                    type: 'object',
                                    nullable: true,
                                    description: 'The registration certificate, or null when none was submitted.',
                                    properties: [
                                        new OA\Property(property: 'id', type: 'integer', example: 501),
                                        new OA\Property(property: 'status', type: 'string', example: 'pending'),
                                        new OA\Property(property: 'mime_type', type: 'string', example: 'application/pdf'),
                                        new OA\Property(property: 'size', type: 'integer', example: 348211),
                                        new OA\Property(property: 'uploaded_at', type: 'string', format: 'date-time', nullable: true),
                                        new OA\Property(property: 'download_url', type: 'string', example: 'http://localhost/api/v1/admin/organizations/12/proof-file'),
                                        new OA\Property(property: 'available', type: 'boolean', description: 'Whether the file is still present on the configured disk.', example: true),
                                    ],
                                ),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated account is not an administrator.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'The requested organization does not exist.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function adminOrganizationsShow(): void {}

    #[OA\Get(
        path: '/v1/admin/organizations/{organization}/proof-file',
        operationId: 'adminOrganizationsProofFile',
        tags: ['Admin'],
        summary: 'Download an organization proof document',
        description: 'Streams the organization\'s registration certificate. The response `Content-Type` is the '
            .'stored `mime_type` of the file (for example `application/pdf` or `image/png`), not always '
            .'`application/octet-stream`. Returns 404 when the organization has no proof record or the file is '
            .'no longer present on the configured disk. Protected by auth:sanctum + account.active + admin.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'organization',
                in: 'path',
                required: true,
                description: 'Organization id.',
                schema: new OA\Schema(type: 'integer', example: 12),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The proof document streamed as a binary file (Content-Type reflects the stored mime type).',
                content: new OA\MediaType(
                    mediaType: 'application/octet-stream',
                    schema: new OA\Schema(type: 'string', format: 'binary'),
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated account is not an administrator.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 404,
                description: 'The organization does not exist, or it has no proof document available on disk.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'Proof file not found.'),
                    ],
                ),
            ),
        ],
    )]
    public function adminOrganizationsProofFile(): void {}

    #[OA\Post(
        path: '/v1/admin/organizations/{organization}/approve',
        operationId: 'adminOrganizationsApprove',
        tags: ['Admin'],
        summary: 'Approve an organization registration request',
        description: 'Marks the organization `verified`, records the reviewing administrator, marks the proof '
            .'document `approved`, writes an audit event and notifies the organization\'s administrators by '
            .'email. Only a `pending` organization can be approved; an already-reviewed one returns 422. An '
            .'organization with no proof document cannot be approved. Takes no request body. Protected by '
            .'auth:sanctum + account.active + admin.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'organization',
                in: 'path',
                required: true,
                description: 'Organization id.',
                schema: new OA\Schema(type: 'integer', example: 12),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The organization has been approved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'The organization has been approved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 12),
                                new OA\Property(property: 'name', type: 'string', example: 'Acme Software'),
                                new OA\Property(property: 'verification_status', type: 'string', example: 'verified'),
                                new OA\Property(property: 'verified_at', type: 'string', format: 'date-time', nullable: true),
                                new OA\Property(
                                    property: 'verified_by',
                                    type: 'object',
                                    nullable: true,
                                    properties: [
                                        new OA\Property(property: 'id', type: 'integer', example: 3),
                                        new OA\Property(property: 'name', type: 'string', example: 'Platform Admin'),
                                        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@skillspan.test'),
                                    ],
                                ),
                                new OA\Property(property: 'proof_file', type: 'object', nullable: true, additionalProperties: true),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated account is not an administrator.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'The requested organization does not exist.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 422,
                description: 'The organization has already been reviewed, or it has no proof document to approve.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'This organization has already been reviewed.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'verification_status', type: 'string', example: 'verified'),
                            ],
                        ),
                    ],
                ),
            ),
        ],
    )]
    public function adminOrganizationsApprove(): void {}

    #[OA\Post(
        path: '/v1/admin/organizations/{organization}/reject',
        operationId: 'adminOrganizationsReject',
        tags: ['Admin'],
        summary: 'Reject an organization registration request',
        description: 'Marks the organization `rejected`, marks the proof document `rejected`, records the '
            .'optional reason in the audit trail and notifies the organization\'s administrators by email. Only '
            .'a `pending` organization can be rejected; an already-reviewed one returns 422. Protected by '
            .'auth:sanctum + account.active + admin.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'organization',
                in: 'path',
                required: true,
                description: 'Organization id.',
                schema: new OA\Schema(type: 'integer', example: 12),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'reason',
                        type: 'string',
                        nullable: true,
                        maxLength: 1000,
                        example: 'The uploaded certificate is not legible.',
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'The organization has been rejected.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'The organization has been rejected.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 12),
                                new OA\Property(property: 'name', type: 'string', example: 'Acme Software'),
                                new OA\Property(property: 'verification_status', type: 'string', example: 'rejected'),
                                new OA\Property(property: 'verified_at', type: 'string', format: 'date-time', nullable: true),
                                new OA\Property(property: 'proof_file', type: 'object', nullable: true, additionalProperties: true),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated account is not an administrator.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'The requested organization does not exist.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 422,
                description: 'Validation error (reason too long), or the organization has already been reviewed.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
        ],
    )]
    public function adminOrganizationsReject(): void {}

    #[OA\Get(
        path: '/v1/admin/projects',
        operationId: 'adminProjectsIndex',
        tags: ['Admin'],
        summary: 'List every project with search, filters and status statistics',
        description: 'Returns every project in the system, newest first, with the relations the admin screen '
            .'renders, plus counts for the summary cards and an echo of the applied filters. Search `q` matches '
            .'title, domain or role (case-insensitive) and accepts a numeric id. Unknown filter values are '
            .'ignored. Server-side paginated (`per_page` is clamped to 1–100, default 15). Each project also '
            .'carries the admin-only `is_editable`, `project_roles` and `eligibility_constraints` fields. '
            .'Protected by auth:sanctum + account.active + admin.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'q',
                in: 'query',
                required: false,
                description: 'Search across title, domain and role (LIKE). A numeric value also matches the project id.',
                schema: new OA\Schema(type: 'string', example: 'checkout'),
            ),
            new OA\Parameter(
                name: 'type',
                in: 'query',
                required: false,
                description: 'Exact-match filter on the project type. Other values are ignored.',
                schema: new OA\Schema(type: 'string', enum: ['simulation', 'company_sponsored'], example: 'company_sponsored'),
            ),
            new OA\Parameter(
                name: 'status',
                in: 'query',
                required: false,
                description: 'Exact-match filter on the project status. `closed` is accepted only so a legacy row stays findable.',
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['draft', 'submitted', 'changes_requested', 'approved', 'rejected', 'open', 'selection', 'active', 'under_review', 'completed', 'cancelled', 'archived', 'closed'],
                    example: 'submitted',
                ),
            ),
            new OA\Parameter(
                name: 'difficulty',
                in: 'query',
                required: false,
                description: 'Exact-match filter on the difficulty level (numeric).',
                schema: new OA\Schema(type: 'number', format: 'float', example: 3),
            ),
            new OA\Parameter(
                name: 'organization_id',
                in: 'query',
                required: false,
                description: 'Filter to projects belonging to this organization (numeric).',
                schema: new OA\Schema(type: 'integer', example: 12),
            ),
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                required: false,
                description: 'Items per page (1–100). Defaults to 15.',
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15, example: 15),
            ),
            new OA\Parameter(
                name: 'page',
                in: 'query',
                required: false,
                description: 'Page number (1-based).',
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Projects retrieved, with statistics and the applied filters.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Projects retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            description: 'Laravel paginator of ProjectResource items, each decorated with the admin-only fields.',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(
                                    property: 'data',
                                    type: 'array',
                                    items: new OA\Items(
                                        allOf: [
                                            new OA\Schema(ref: '#/components/schemas/Project'),
                                            new OA\Schema(
                                                properties: [
                                                    new OA\Property(property: 'is_editable', type: 'boolean', description: 'Whether the project is in an editable status (draft or changes_requested).', example: true),
                                                    new OA\Property(
                                                        property: 'project_roles',
                                                        type: 'array',
                                                        description: 'EVERY stored project role, including inactive ones.',
                                                        items: new OA\Items(
                                                            type: 'object',
                                                            properties: [
                                                                new OA\Property(property: 'id', type: 'integer', example: 71),
                                                                new OA\Property(property: 'title', type: 'string', example: 'Backend Developer'),
                                                                new OA\Property(property: 'description', type: 'string', nullable: true),
                                                                new OA\Property(property: 'is_active', type: 'boolean', example: true),
                                                            ],
                                                        ),
                                                    ),
                                                    new OA\Property(
                                                        property: 'eligibility_constraints',
                                                        type: 'array',
                                                        description: 'The stored eligibility constraint rows (not the per-learner verdict).',
                                                        items: new OA\Items(
                                                            type: 'object',
                                                            properties: [
                                                                new OA\Property(property: 'id', type: 'integer', example: 9),
                                                                new OA\Property(property: 'constraint_type', type: 'string', example: 'location'),
                                                                new OA\Property(property: 'value', type: 'string', example: 'Jordan'),
                                                            ],
                                                        ),
                                                    ),
                                                ],
                                            ),
                                        ],
                                    ),
                                ),
                                new OA\Property(property: 'first_page_url', type: 'string', nullable: true),
                                new OA\Property(property: 'from', type: 'integer', nullable: true, example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 4),
                                new OA\Property(property: 'last_page_url', type: 'string', nullable: true),
                                new OA\Property(property: 'next_page_url', type: 'string', nullable: true),
                                new OA\Property(property: 'path', type: 'string', example: 'http://localhost/api/v1/admin/projects'),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'prev_page_url', type: 'string', nullable: true),
                                new OA\Property(property: 'to', type: 'integer', nullable: true, example: 15),
                                new OA\Property(property: 'total', type: 'integer', example: 52),
                            ],
                        ),
                        new OA\Property(
                            property: 'stats',
                            type: 'object',
                            description: 'Whole-table counts, independent of the active filters.',
                            properties: [
                                new OA\Property(property: 'total', type: 'integer', example: 52),
                                new OA\Property(property: 'draft', type: 'integer', example: 7),
                                new OA\Property(property: 'awaiting_review', type: 'integer', example: 5),
                                new OA\Property(property: 'open', type: 'integer', example: 21),
                            ],
                        ),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f1c9e2a-8b7d-4c1e-9f2a-1d2e3f4a5b6c'),
                        new OA\Property(
                            property: 'filters',
                            type: 'object',
                            description: 'The filters that were actually applied (empty values omitted).',
                            additionalProperties: true,
                            example: ['status' => 'submitted', 'type' => 'company_sponsored'],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated account is not an administrator.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 500, description: 'Unexpected server error (code PROJECT_REQUEST_FAILED).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function adminProjectsIndex(): void {}

    #[OA\Get(
        path: '/v1/admin/projects/{project}',
        operationId: 'adminProjectsShow',
        tags: ['Admin'],
        summary: 'Retrieve one project with admin details',
        description: 'Returns a single project with the relations the detail screen renders, plus the admin-only '
            .'`is_editable`, `project_roles` and `eligibility_constraints` fields. Protected by auth:sanctum + '
            .'account.active + admin.',
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
                description: 'Project retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Project retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            allOf: [
                                new OA\Schema(ref: '#/components/schemas/Project'),
                                new OA\Schema(
                                    properties: [
                                        new OA\Property(property: 'is_editable', type: 'boolean', example: false),
                                        new OA\Property(
                                            property: 'project_roles',
                                            type: 'array',
                                            items: new OA\Items(
                                                type: 'object',
                                                properties: [
                                                    new OA\Property(property: 'id', type: 'integer', example: 71),
                                                    new OA\Property(property: 'title', type: 'string', example: 'Backend Developer'),
                                                    new OA\Property(property: 'description', type: 'string', nullable: true),
                                                    new OA\Property(property: 'is_active', type: 'boolean', example: true),
                                                ],
                                            ),
                                        ),
                                        new OA\Property(
                                            property: 'eligibility_constraints',
                                            type: 'array',
                                            items: new OA\Items(
                                                type: 'object',
                                                properties: [
                                                    new OA\Property(property: 'id', type: 'integer', example: 9),
                                                    new OA\Property(property: 'constraint_type', type: 'string', example: 'location'),
                                                    new OA\Property(property: 'value', type: 'string', example: 'Jordan'),
                                                ],
                                            ),
                                        ),
                                    ],
                                ),
                            ],
                        ),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f1c9e2a-8b7d-4c1e-9f2a-1d2e3f4a5b6c'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated account is not an administrator.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'The requested project does not exist (code PROJECT_NOT_FOUND).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 500, description: 'Unexpected server error (code PROJECT_REQUEST_FAILED).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function adminProjectsShow(): void {}

    #[OA\Post(
        path: '/v1/admin/projects',
        operationId: 'adminProjectsStore',
        tags: ['Admin'],
        summary: 'Create a project from the admin panel (starts as a draft)',
        description: 'Creates a project using the same form request and lifecycle service as the owner API, so '
            .'validation rules and the "starts as draft" rule do not fork. The project always starts as `draft`. '
            .'`organization_id` and `owner_id` are only honoured when a platform administrator creates a '
            .'company_sponsored project on behalf of a company; otherwise they are ignored. Date ordering '
            .'(end >= start, deadline <= start) is validated by the service against the final state. Protected '
            .'by auth:sanctum + account.active + admin.',
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
                        new OA\Property(
                            property: 'data',
                            allOf: [
                                new OA\Schema(ref: '#/components/schemas/Project'),
                                new OA\Schema(
                                    properties: [
                                        new OA\Property(property: 'is_editable', type: 'boolean', example: true),
                                        new OA\Property(property: 'project_roles', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true)),
                                        new OA\Property(property: 'eligibility_constraints', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true)),
                                    ],
                                ),
                            ],
                        ),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f1c9e2a-8b7d-4c1e-9f2a-1d2e3f4a5b6c'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'The caller may not create projects, or may not administer the named organization (codes PROJECT_CREATE_FORBIDDEN, PROJECT_ORGANIZATION_REQUIRED, PROJECT_ORGANIZATION_NOT_ADMINISTERED).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error, or a business-rule failure such as PROJECT_TYPE_INVALID, PROJECT_OWNER_REQUIRED, PROJECT_OWNER_INVALID, PROJECT_ORGANIZATION_NOT_FOUND, PROJECT_ORGANIZATION_NOT_A_COMPANY, PROJECT_ORGANIZATION_NOT_VERIFIED or PROJECT_INVALID_DATES.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
            new OA\Response(response: 500, description: 'Unexpected server error (code PROJECT_REQUEST_FAILED).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function adminProjectsStore(): void {}

    #[OA\Patch(
        path: '/v1/admin/projects/{project}',
        operationId: 'adminProjectsUpdate',
        tags: ['Admin'],
        summary: 'Update a project from the admin panel',
        description: 'Partially updates a project. Every top-level field is optional, so an omitted field is left '
            .'untouched; supplying a child set (required_skills, roles, eligibility_constraints) replaces it '
            .'wholesale. Only a project in `draft` or `changes_requested` may be edited; an approved or open '
            .'project is refused with PROJECT_NOT_EDITABLE. Authorization (owner or platform administrator) is '
            .'enforced by ProjectLifecycleService. Protected by auth:sanctum + account.active + admin.',
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
                        new OA\Property(
                            property: 'data',
                            allOf: [
                                new OA\Schema(ref: '#/components/schemas/Project'),
                                new OA\Schema(
                                    properties: [
                                        new OA\Property(property: 'is_editable', type: 'boolean', example: true),
                                        new OA\Property(property: 'project_roles', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true)),
                                        new OA\Property(property: 'eligibility_constraints', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true)),
                                    ],
                                ),
                            ],
                        ),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f1c9e2a-8b7d-4c1e-9f2a-1d2e3f4a5b6c'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'The caller is not the project owner, or may not link the named organization (codes PROJECT_NOT_OWNED, PROJECT_ORGANIZATION_NOT_ADMINISTERED, PROJECT_ORGANIZATION_REQUIRED).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(response: 404, description: 'The requested project does not exist (code PROJECT_NOT_FOUND).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 409,
                description: 'The project status changed while the request was being processed (code PROJECT_STATUS_CONFLICT).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error, or a business-rule failure such as PROJECT_NOT_EDITABLE or PROJECT_INVALID_DATES.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
            new OA\Response(response: 500, description: 'Unexpected server error (code PROJECT_REQUEST_FAILED).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function adminProjectsUpdate(): void {}

    #[OA\Post(
        path: '/v1/admin/projects/{project}/cancel',
        operationId: 'adminProjectsCancel',
        tags: ['Admin'],
        summary: 'Cancel a project (soft delete)',
        description: 'Marks a project `cancelled`, preserving its applications, recommendations and audit trail. '
            .'This is not a general status editor: it accepts no target status, so the only reachable transition '
            .'is the one defined here. An optional `reason` is forwarded to ProjectLifecycleService and recorded. '
            .'A project that has already reached a final state (cancelled/archived) is reported as such rather '
            .'than silently re-cancelled. Protected by auth:sanctum + account.active + admin.',
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
                    new OA\Property(property: 'reason', type: 'string', nullable: true, example: 'Sponsor withdrew the project.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Project cancelled.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Project cancelled.'),
                        new OA\Property(
                            property: 'data',
                            allOf: [
                                new OA\Schema(ref: '#/components/schemas/Project'),
                                new OA\Schema(
                                    properties: [
                                        new OA\Property(property: 'is_editable', type: 'boolean', example: false),
                                        new OA\Property(property: 'project_roles', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true)),
                                        new OA\Property(property: 'eligibility_constraints', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true)),
                                    ],
                                ),
                            ],
                        ),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f1c9e2a-8b7d-4c1e-9f2a-1d2e3f4a5b6c'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'The caller is not the project owner or a platform administrator (code PROJECT_NOT_OWNED).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'The requested project does not exist (code PROJECT_NOT_FOUND).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 409,
                description: 'The project has already reached a final state, or its status changed while the request was being processed (code PROJECT_STATUS_CONFLICT).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 422,
                description: 'The transition is not allowed (code PROJECT_INVALID_TRANSITION).',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(response: 500, description: 'Unexpected server error (code PROJECT_REQUEST_FAILED).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function adminProjectsCancel(): void {}
}
