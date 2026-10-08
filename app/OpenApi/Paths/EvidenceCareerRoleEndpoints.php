<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI documentation for App\Http\Controllers\Api\EvidenceController
 * and App\Http\Controllers\Api\CareerRoleController.
 * Paths are relative to the `/api` server, so they carry the `/v1` prefix
 * exactly as registered in routes/api.php.
 */
class EvidenceCareerRoleEndpoints
{
    #[OA\Post(
        path: '/v1/evidence',
        operationId: 'evidenceStore',
        tags: ['Evidence'],
        summary: 'Submit evidence for a skill',
        description: 'Creates a new evidence record for the authenticated learner, linked to an active skill. '
            .'The record is stored as `pending` until an administrator reviews it. Either `evidence_url` or an '
            .'uploaded `evidence_file` must be supplied. Submitting a combination of learner, skill, source '
            .'(`certificate`) and reference that already exists is rejected as a duplicate.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['skill_id'],
                    properties: [
                        new OA\Property(property: 'skill_id', type: 'integer', description: 'Must reference an existing skill whose status is `active`.', example: 15),
                        new OA\Property(property: 'evidence_url', type: 'string', format: 'uri', nullable: true, description: 'Required when `evidence_file` is not sent.', example: 'https://example.com/certs/php-advanced.pdf'),
                        new OA\Property(property: 'evidence_file', type: 'string', format: 'binary', nullable: true, description: 'Required when `evidence_url` is not sent. Maximum 10000 KB.', example: 'certificate.pdf'),
                        new OA\Property(property: 'description', type: 'string', nullable: true, maxLength: 500, example: 'Completed the advanced PHP certification.'),
                        new OA\Property(property: 'evidence_date', type: 'string', format: 'date', nullable: true, description: 'Defaults to today when omitted.', example: '2026-01-15'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Evidence submitted; pending review.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Evidence submitted successfully, pending review.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Evidence'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden — the account is not a learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Duplicate evidence already exists for this skill.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error, or the account has no student profile (`success`/`message` envelope).', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function evidenceStore(): void {}

    #[OA\Get(
        path: '/v1/evidence',
        operationId: 'evidenceIndex',
        tags: ['Evidence'],
        summary: 'List verified evidence for the authenticated learner',
        description: 'Returns only the evidence records whose `verification_status` is `verified`, belonging to '
            .'the authenticated learner\'s student profile, with the related skill and reviewer eager-loaded.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Evidence retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Evidence retrieved successfully.'),
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Evidence')),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden — the account is not a learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'The account has no student profile yet.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function evidenceIndex(): void {}

    #[OA\Get(
        path: '/v1/evidence/{id}',
        operationId: 'evidenceShow',
        tags: ['Evidence'],
        summary: 'Retrieve a single evidence record',
        description: 'Administrators can access any evidence record. Other users can only access evidence that '
            .'belongs to their own student profile — the ownership check is part of the query, so another '
            .'learner\'s record is indistinguishable from a missing one (404).',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Evidence record id.', schema: new OA\Schema(type: 'integer'), example: 301),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Evidence retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Evidence retrieved successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Evidence'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Evidence record not found, or not owned by the caller.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'The account has no student profile yet.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function evidenceShow(): void {}

    #[OA\Put(
        path: '/v1/evidence/{id}/review',
        operationId: 'evidenceReview',
        tags: ['Evidence'],
        summary: 'Review evidence (administrator only)',
        description: 'Marks an evidence record as `verified` or `rejected`, records the reviewer and optional '
            .'notes, recalculates the learner\'s skill level and confidence synchronously, and dispatches a '
            .'queued intelligence recalculation for the learner.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Evidence record id.', schema: new OA\Schema(type: 'integer'), example: 301),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['verification_status'],
                properties: [
                    new OA\Property(property: 'verification_status', type: 'string', enum: ['verified', 'rejected'], example: 'verified'),
                    new OA\Property(property: 'reviewer_notes', type: 'string', nullable: true, maxLength: 500, example: 'Certificate verified against the issuer registry.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Evidence reviewed.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Evidence verified successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Evidence'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden — the account is not an administrator.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Evidence record not found.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error (invalid or missing `verification_status`).', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function evidenceReview(): void {}

    #[OA\Get(
        path: '/v1/career-roles',
        operationId: 'careerRolesIndex',
        tags: ['Career Roles'],
        summary: 'List approved career roles',
        description: 'Returns approved career roles ordered by version (descending) with a deterministic '
            .'`id` tie-breaker, paginated at a fixed 15 items per page. Each item carries a `skills_count`. '
            .'The response echoes (or generates) a `request_id`, also returned in the `X-Request-ID` header. '
            .'When `specialization_id` is supplied, only the approved career roles linked to that '
            .'specialization (via the career_role_specialization pivot) are returned — this is the filter '
            .'the frontend uses to drive the specialization -> career role -> skills -> questions flow. '
            .'The "Self-Learning / Free Track" specialization is the exception: it carries no pivot rows and '
            .'returns EVERY approved career role, so a self-taught learner can pick any role. '
            .'Omitting the filter preserves the original "list every approved role" behaviour.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'specialization_id',
                in: 'query',
                required: false,
                description: 'Optional filter. Must be a positive integer referencing an existing specialization. '
                    .'When present, only approved career roles linked to that specialization are returned — except '
                    .'for the "Self-Learning / Free Track", which returns every approved role. '
                    .'When omitted, every approved career role is returned (unchanged behaviour).',
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 2),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Career roles retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Career roles retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            description: 'Laravel length-aware paginator (per page fixed at 15).',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'data', type: 'array', description: 'Each item is a CareerRole plus a `skills_count`.', items: new OA\Items(ref: '#/components/schemas/CareerRole')),
                                new OA\Property(property: 'first_page_url', type: 'string', nullable: true, example: 'http://localhost/api/v1/career-roles?page=1'),
                                new OA\Property(property: 'from', type: 'integer', nullable: true, example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 3),
                                new OA\Property(property: 'last_page_url', type: 'string', nullable: true, example: 'http://localhost/api/v1/career-roles?page=3'),
                                new OA\Property(property: 'links', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true)),
                                new OA\Property(property: 'next_page_url', type: 'string', nullable: true, example: 'http://localhost/api/v1/career-roles?page=2'),
                                new OA\Property(property: 'path', type: 'string', example: 'http://localhost/api/v1/career-roles'),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'prev_page_url', type: 'string', nullable: true),
                                new OA\Property(property: 'to', type: 'integer', nullable: true, example: 15),
                                new OA\Property(property: 'total', type: 'integer', example: 42),
                            ],
                        ),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f2504e0-4f89-41d3-9a0c-0305e82c3301'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden — the account is not a learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error — `specialization_id` is not a positive integer or does not reference an existing specialization.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function careerRolesIndex(): void {}

    #[OA\Get(
        path: '/v1/career-roles/{id}',
        operationId: 'careerRolesShow',
        tags: ['Career Roles'],
        summary: 'Retrieve an approved career role',
        description: 'Returns a single approved career role. Only approved roles are visible through this API; '
            .'an unknown id returns `CAREER_ROLE_NOT_FOUND`, while an existing but non-approved role returns '
            .'`CAREER_ROLE_NOT_APPROVED`. The response echoes a `request_id`.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Career role id.', schema: new OA\Schema(type: 'integer'), example: 3),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Career role retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Career role retrieved successfully.'),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f2504e0-4f89-41d3-9a0c-0305e82c3301'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/CareerRole'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden — the account is not a learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: '`CAREER_ROLE_NOT_FOUND` — the career role does not exist.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: '`CAREER_ROLE_NOT_APPROVED` — the career role exists but is not approved.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function careerRolesShow(): void {}

    #[OA\Get(
        path: '/v1/career-roles/{id}/skills',
        operationId: 'careerRolesSkills',
        tags: ['Career Roles'],
        summary: 'Retrieve the career role decision snapshot',
        description: 'Returns the point-in-time decision snapshot for an approved role: its required skills with '
            .'required level, importance weight, critical flag and prerequisites, plus aggregate statistics and '
            .'snapshot metadata. Unknown ids return `CAREER_ROLE_NOT_FOUND`, non-approved roles return '
            .'`CAREER_ROLE_NOT_APPROVED`, and an approved role without required skills returns '
            .'`CAREER_ROLE_NO_SKILLS`. The response echoes a `request_id`.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Career role id.', schema: new OA\Schema(type: 'integer'), example: 3),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Career role decision snapshot prepared.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Career role decision snapshot prepared successfully.'),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f2504e0-4f89-41d3-9a0c-0305e82c3301'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/CareerRoleSnapshot'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden — the account is not a learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: '`CAREER_ROLE_NOT_FOUND` — the career role does not exist.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: '`CAREER_ROLE_NOT_APPROVED` (role exists but is not approved) or `CAREER_ROLE_NO_SKILLS` (approved role has no required skills).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function careerRolesSkills(): void {}
}
