<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI documentation for Talent Discovery
 * (App\Http\Controllers\Api\TalentDiscoveryController).
 *
 * This is the company-facing list of students who have made themselves
 * visible. It deliberately replaces the frontend's use of
 * `GET /v1/recommendations` for that page: that endpoint returns the CALLER's
 * own stored PROJECT recommendations and is gated on `role:learner`, so a
 * company admin could never have reached it.
 *
 * Paths are relative to the `/api` server, so they carry the `/v1` prefix
 * exactly as registered in routes/api.php.
 */
class TalentDiscoveryEndpoints
{
    #[OA\Get(
        path: '/v1/talent',
        operationId: 'talentDiscoveryIndex',
        tags: ['Talent Discovery'],
        summary: 'List discoverable talent (paginated, filtered)',
        description: 'Returns the students the caller is permitted to see, newest-readiness first by '
            .'default. The visibility rule is the same shape as the mentor-student one, because "which '
            .'students may this account see?" must have exactly one answer platform-wide: a student is '
            .'returned when the caller has an active mentor connection with them, OR the profile '
            .'visibility is `public`, OR the visibility is `organization_only` and the student shares an '
            .'active organization membership with the caller. Talent Discovery adds one gate the mentor '
            .'path does not need, because a company is a third party rather than a coach: '
            .'`consent_given` must be true. '
            .'⚠️ `visibility` defaults to `private` and `consent_given` to false, so an empty `data` '
            .'array is the expected state until students opt in — not an error. '
            .'Protected by auth:sanctum + account.active + organization.approved + role:company_admin.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'search',
                in: 'query',
                required: false,
                description: 'Case-insensitive partial match against the student name OR the career-role title.',
                schema: new OA\Schema(type: 'string', maxLength: 255, example: 'Lena'),
            ),
            new OA\Parameter(
                name: 'career_role',
                in: 'query',
                required: false,
                description: 'Career-role slug. Get the usable values from `GET /v1/talent/filters` — the '
                    .'caller cannot read `GET /v1/career-roles`, which is `role:learner` only.',
                schema: new OA\Schema(type: 'string', maxLength: 255, example: 'backend-engineer'),
            ),
            new OA\Parameter(
                name: 'min_readiness',
                in: 'query',
                required: false,
                description: 'Only return students whose LATEST readiness score is at least this value.',
                schema: new OA\Schema(type: 'number', format: 'float', minimum: 0, maximum: 100, example: 70),
            ),
            new OA\Parameter(
                name: 'sort',
                in: 'query',
                required: false,
                description: '`readiness` (default) sorts by latest readiness score, highest first; `recent` '
                    .'sorts by newest profile. An unsupported value is a 422 rather than a silent default.',
                schema: new OA\Schema(type: 'string', enum: ['readiness', 'recent'], default: 'readiness'),
            ),
            new OA\Parameter(
                name: 'page',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1),
            ),
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                required: false,
                description: 'Page size. Capped at 100; above the cap is a 422.',
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Discoverable talent retrieved. May legitimately be empty.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Discoverable talent retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', description: 'The student (user) id.', example: 42),
                                    new OA\Property(property: 'name', type: 'string', nullable: true, example: 'Lena Kim'),
                                    new OA\Property(
                                        property: 'career_role',
                                        type: 'object',
                                        nullable: true,
                                        properties: [
                                            new OA\Property(property: 'id', type: 'integer', example: 1),
                                            new OA\Property(property: 'title', type: 'string', example: 'Backend Engineer'),
                                            new OA\Property(property: 'slug', type: 'string', example: 'backend-engineer'),
                                        ],
                                    ),
                                    new OA\Property(property: 'specialization', type: 'string', nullable: true, example: 'Computer Science'),
                                    new OA\Property(property: 'university_name', type: 'string', nullable: true, example: 'Cairo University'),
                                    new OA\Property(property: 'career_status', type: 'string', nullable: true, example: 'student'),
                                    new OA\Property(property: 'enrollment_status', type: 'string', nullable: true, example: 'enrolled'),
                                    new OA\Property(property: 'availability', type: 'string', nullable: true, example: 'full_time'),
                                    new OA\Property(property: 'preferred_work_type', type: 'string', nullable: true, example: 'remote'),
                                    new OA\Property(property: 'completeness_percent', type: 'integer', example: 82),
                                    new OA\Property(property: 'visibility', type: 'string', nullable: true, example: 'public'),
                                    new OA\Property(property: 'contactable', type: 'boolean', description: 'Always true for a listed student (consent is an inclusion condition) but reported explicitly so the client can drive the contact affordance off the payload.', example: true),
                                    new OA\Property(
                                        property: 'readiness',
                                        type: 'object',
                                        properties: [
                                            new OA\Property(property: 'score', type: 'number', format: 'float', nullable: true, example: 78.5),
                                            new OA\Property(property: 'band', type: 'string', nullable: true, example: 'near_ready'),
                                        ],
                                    ),
                                    new OA\Property(
                                        property: 'top_skills',
                                        type: 'array',
                                        description: 'At most 3 skills, highest level first.',
                                        items: new OA\Items(ref: '#/components/schemas/TalentSkill'),
                                    ),
                                ],
                            ),
                        ),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 1),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'total', type: 'integer', example: 1),
                            ],
                        ),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'The caller is not a company admin (code COMPANY_ADMIN_ONLY), the account is not '
                    .'active, or the organization is not approved.',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error (e.g. `per_page` above 100, `min_readiness` outside 0-100, or an unsupported `sort`).',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
        ],
    )]
    public function talentDiscoveryIndex(): void {}

    #[OA\Get(
        path: '/v1/talent/filters',
        operationId: 'talentDiscoveryFilters',
        tags: ['Talent Discovery'],
        summary: 'List the filter values usable on the talent list',
        description: 'Returns the values the list endpoint accepts, so a client never has to hardcode them. '
            .'This exists because a company admin cannot read `GET /v1/career-roles` (all three of its '
            .'routes are `role:learner`), so the `career_role` slugs would otherwise be unreachable '
            .'without guessing. `career_roles` is deliberately NOT the whole taxonomy: it is the set that '
            .'can actually return a result for THIS caller, derived from the same permitted-student query '
            .'as the list. A dropdown built from the full table would offer options that always come back '
            .'empty. Protected by auth:sanctum + account.active + organization.approved + role:company_admin.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Filter values retrieved. `career_roles` may be empty.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Talent discovery filters retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(
                                    property: 'career_roles',
                                    type: 'array',
                                    description: 'Only the roles held by at least one student this caller may see.',
                                    items: new OA\Items(
                                        type: 'object',
                                        properties: [
                                            new OA\Property(property: 'id', type: 'integer', example: 1),
                                            new OA\Property(property: 'title', type: 'string', example: 'Backend Engineer'),
                                            new OA\Property(property: 'slug', type: 'string', description: 'Pass this as the `career_role` query parameter.', example: 'backend-engineer'),
                                        ],
                                    ),
                                ),
                                new OA\Property(
                                    property: 'readiness_bands',
                                    type: 'array',
                                    description: 'The `readiness.band` vocabulary, so the client can label a band without hardcoding it.',
                                    items: new OA\Items(type: 'string'),
                                    example: ['foundation_needed', 'developing', 'moderate_readiness', 'near_ready', 'highly_ready'],
                                ),
                            ],
                        ),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'The caller is not a company admin (code COMPANY_ADMIN_ONLY), the account is not '
                    .'active, or the organization is not approved.',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function talentDiscoveryFilters(): void {}

    #[OA\Get(
        path: '/v1/talent/{student}',
        operationId: 'talentDiscoveryShow',
        tags: ['Talent Discovery'],
        summary: "Get one student's talent profile",
        description: 'Returns the identity block plus the three tabs the UI draws (skills, projects, '
            .'evaluation). The SAME visibility rules as the list are applied here — never weaker — so a '
            .'caller cannot reach a student by guessing an id the list would not have shown them. '
            .'⚠️ A student the caller may not see is answered with **404, never 403**: a 403 would confirm '
            .'that the id exists and turn this route into a membership oracle. The same 404 covers an '
            .'unknown id, a private profile, a student who did not consent, and another tenant. '
            .'The student email address is not part of this contract on purpose, and neither is the '
            .'evaluator identity, the per-dimension comments, nor `moderation_reason` — that is internal '
            .'review material. Protected by auth:sanctum + account.active + organization.approved + '
            .'role:company_admin.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'student',
                in: 'path',
                required: true,
                description: 'Student (user) id. Must be numeric — a non-numeric segment does not match this route.',
                schema: new OA\Schema(type: 'integer', example: 42),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Talent profile retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Talent profile retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 42),
                                new OA\Property(property: 'name', type: 'string', nullable: true, example: 'Lena Kim'),
                                new OA\Property(
                                    property: 'career_role',
                                    type: 'object',
                                    nullable: true,
                                    properties: [
                                        new OA\Property(property: 'id', type: 'integer', example: 1),
                                        new OA\Property(property: 'title', type: 'string', example: 'Backend Engineer'),
                                        new OA\Property(property: 'slug', type: 'string', example: 'backend-engineer'),
                                    ],
                                ),
                                new OA\Property(property: 'specialization', type: 'string', nullable: true, example: 'Computer Science'),
                                new OA\Property(property: 'university_name', type: 'string', nullable: true, example: 'Cairo University'),
                                new OA\Property(property: 'career_status', type: 'string', nullable: true, example: 'student'),
                                new OA\Property(property: 'enrollment_status', type: 'string', nullable: true, example: 'enrolled'),
                                new OA\Property(property: 'availability', type: 'string', nullable: true, example: 'full_time'),
                                new OA\Property(property: 'preferred_work_type', type: 'string', nullable: true, example: 'remote'),
                                new OA\Property(property: 'completeness_percent', type: 'integer', example: 82),
                                new OA\Property(property: 'visibility', type: 'string', nullable: true, example: 'public'),
                                new OA\Property(property: 'contactable', type: 'boolean', example: true),
                                new OA\Property(
                                    property: 'readiness',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'score', type: 'number', format: 'float', nullable: true, example: 78.5),
                                        new OA\Property(property: 'band', type: 'string', nullable: true, example: 'near_ready'),
                                    ],
                                ),
                                new OA\Property(property: 'bio', type: 'string', nullable: true, example: 'Final-year CS student focused on backend APIs.'),
                                new OA\Property(property: 'interests', type: 'array', items: new OA\Items(type: 'string'), example: ['distributed systems', 'developer tooling']),
                                new OA\Property(property: 'academic_level', type: 'string', nullable: true, example: '4th year'),
                                new OA\Property(property: 'expected_graduation', type: 'string', nullable: true, example: '2027-06-30'),
                                new OA\Property(property: 'graduation_status', type: 'boolean', example: false),
                                new OA\Property(
                                    property: 'skills',
                                    type: 'array',
                                    description: 'Up to 20 skills, highest level first.',
                                    items: new OA\Items(ref: '#/components/schemas/TalentSkill'),
                                ),
                                new OA\Property(
                                    property: 'projects',
                                    type: 'array',
                                    description: 'Up to 20 projects the student is or was assigned to.',
                                    items: new OA\Items(
                                        type: 'object',
                                        properties: [
                                            new OA\Property(property: 'id', type: 'integer', example: 55),
                                            new OA\Property(property: 'title', type: 'string', nullable: true, example: 'E-Commerce Platform Rebuild'),
                                            new OA\Property(property: 'type', type: 'string', nullable: true, example: 'simulation'),
                                            new OA\Property(property: 'role', type: 'string', nullable: true, example: 'Frontend Lead'),
                                            new OA\Property(property: 'state', type: 'string', nullable: true, example: 'completed'),
                                            new OA\Property(property: 'sponsor_org', type: 'string', nullable: true, example: 'Acme Software'),
                                            new OA\Property(property: 'joined_at', type: 'string', format: 'date', nullable: true, example: '2026-01-15'),
                                            new OA\Property(property: 'left_at', type: 'string', format: 'date', nullable: true, example: '2026-05-20'),
                                        ],
                                    ),
                                ),
                                new OA\Property(
                                    property: 'evaluation',
                                    type: 'object',
                                    description: 'Aggregated from the student\'s FINALIZED project evaluations. The evaluator identity, per-dimension comments and moderation reason are withheld.',
                                    properties: [
                                        new OA\Property(property: 'authorized', type: 'boolean', description: 'False when there is nothing finalized to show; every other field is then null/empty.', example: true),
                                        new OA\Property(property: 'overall_rating', type: 'number', format: 'float', nullable: true, example: 4.5),
                                        new OA\Property(
                                            property: 'criteria_scores',
                                            type: 'array',
                                            description: '`key` is the rubric-dimension enum key, not a display label — the client owns the label and its translation.',
                                            items: new OA\Items(
                                                type: 'object',
                                                properties: [
                                                    new OA\Property(property: 'key', type: 'string', enum: ['quality', 'commitment', 'communication', 'collaboration', 'creativity', 'problem_solving', 'delivery'], example: 'quality'),
                                                    new OA\Property(property: 'score', type: 'number', format: 'float', example: 4.6),
                                                ],
                                            ),
                                        ),
                                        new OA\Property(property: 'evaluations_count', type: 'integer', example: 3),
                                        new OA\Property(property: 'reviewed_at', type: 'string', format: 'date', nullable: true, example: '2026-06-01'),
                                    ],
                                ),
                            ],
                        ),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'The caller is not a company admin (code COMPANY_ADMIN_ONLY), the account is not '
                    .'active, or the organization is not approved.',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 404,
                description: 'No discoverable student with that id — covers an unknown id, a private profile, a student who did not consent, and another tenant, with one indistinguishable response.',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function talentDiscoveryShow(): void {}
}
