<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI documentation for the self-service organization profile endpoint
 * (App\Http\Controllers\Api\OrganizationController) and the mentor-student
 * communication system (App\Http\Controllers\Api\MentorStudentController).
 * Paths are relative to the `/api` server, so they carry the `/v1` prefix
 * exactly as registered in routes/api.php.
 */
class OrganizationMentorEndpoints
{
    #[OA\Get(
        path: '/v1/organization/profile',
        operationId: 'organizationProfile',
        tags: ['Organization'],
        summary: "Get the authenticated organization admin's profile",
        description: 'Returns the profile of the organization the caller actively administers. The '
            .'organization used for the payload and the one that establishes admin rights are the same '
            .'row: the caller must be an `admin` member (pivot `role_in_org`) whose membership status is '
            .'`active`. A caller with no organization at all receives 403 "This account is not linked to '
            .'any organization."; a caller who is not an active admin of one receives 403 "Only an '
            .'organization admin can view this profile." Protected by auth:sanctum + account.active + '
            .'organization.approved.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Organization profile retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Organization profile retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 12),
                                new OA\Property(property: 'name', type: 'string', example: 'Acme Software'),
                                new OA\Property(property: 'type', type: 'string', nullable: true, example: 'company'),
                                new OA\Property(property: 'verification_status', type: 'string', nullable: true, example: 'approved'),
                                new OA\Property(property: 'contact_email', type: 'string', format: 'email', nullable: true, example: 'contact@acme.com'),
                                new OA\Property(property: 'contact_phone', type: 'string', nullable: true, example: '+962790000000'),
                                new OA\Property(property: 'website', type: 'string', nullable: true, example: 'https://acme.example.com'),
                                new OA\Property(property: 'industry', type: 'string', nullable: true, example: 'Software'),
                                new OA\Property(property: 'company_size', type: 'string', nullable: true, example: '51-200'),
                                new OA\Property(property: 'country', type: 'string', nullable: true, example: 'Jordan'),
                                new OA\Property(property: 'city', type: 'string', nullable: true, example: 'Amman'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'The account is not linked to any organization, or the caller is not an active admin of one.',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function organizationProfile(): void {}

    #[OA\Get(
        path: '/v1/mentor/students',
        operationId: 'mentorStudents',
        tags: ['Mentor'],
        summary: 'List students the mentor is permitted to see',
        description: 'Returns every student profile the mentor is allowed to view. Visibility rules '
            .'(enforced in the service): students with an active/pending connection to this mentor, '
            .'students whose profile visibility is `public`, and students whose visibility is '
            .'`organization_only` when they share an active organization membership with the mentor. '
            .'No query filters are supported. Protected by auth:sanctum + account.active + mentor '
            .'(verified ProfessionalProfile of type `mentor`).',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Permitted students retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Permitted students retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 42),
                                    new OA\Property(property: 'name', type: 'string', nullable: true, example: 'Sara Ahmad'),
                                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, example: 'sara@example.com'),
                                    new OA\Property(property: 'university_name', type: 'string', nullable: true, example: 'University of Jordan'),
                                    new OA\Property(property: 'specialization', type: 'string', nullable: true, example: 'Computer Science'),
                                    new OA\Property(property: 'career_status', type: 'string', nullable: true, example: 'student'),
                                    new OA\Property(property: 'visibility', type: 'string', nullable: true, example: 'public'),
                                    new OA\Property(property: 'completeness_percent', type: 'integer', nullable: true, example: 80),
                                ],
                            ),
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'The caller is not a verified mentor.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function mentorStudents(): void {}

    #[OA\Get(
        path: '/v1/mentor/students/{student}',
        operationId: 'mentorStudentSummary',
        tags: ['Mentor'],
        summary: "Get a student's summary",
        description: 'Returns the summary of a single student, provided the mentor is permitted to see '
            .'them (active/pending connection, `public` visibility, or a shared active organization for '
            .'`organization_only` profiles). Returns 403 (code STUDENT_NOT_VISIBLE) when the mentor is '
            .'not permitted, and 422 (code STUDENT_PROFILE_NOT_FOUND) when the user has no student '
            .'profile. Protected by auth:sanctum + account.active + mentor.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'student',
                in: 'path',
                required: true,
                description: 'Student (user) id.',
                schema: new OA\Schema(type: 'integer', example: 42),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Student summary retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 42),
                                new OA\Property(property: 'name', type: 'string', example: 'Sara Ahmad'),
                                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'sara@example.com'),
                                new OA\Property(property: 'university_name', type: 'string', nullable: true, example: 'University of Jordan'),
                                new OA\Property(property: 'specialization', type: 'string', nullable: true, example: 'Computer Science'),
                                new OA\Property(property: 'career_status', type: 'string', nullable: true, example: 'student'),
                                new OA\Property(property: 'interests', type: 'array', nullable: true, items: new OA\Items(type: 'string'), example: ['web development', 'data science']),
                                new OA\Property(property: 'availability', type: 'string', nullable: true, example: 'part_time'),
                                new OA\Property(property: 'preferred_work_type', type: 'string', nullable: true, example: 'remote'),
                                new OA\Property(property: 'visibility', type: 'string', nullable: true, example: 'public'),
                                new OA\Property(property: 'completeness_percent', type: 'integer', example: 80),
                                new OA\Property(property: 'enrollment_status', type: 'string', nullable: true, example: 'enrolled'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Not permitted to view this student (code STUDENT_NOT_VISIBLE) or not a verified mentor.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'The specified student does not have a student profile (code STUDENT_PROFILE_NOT_FOUND).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function mentorStudentSummary(): void {}

    #[OA\Post(
        path: '/v1/mentor/connections',
        operationId: 'mentorConnect',
        tags: ['Mentor'],
        summary: 'Create a mentor-student connection',
        description: 'Creates a connection between the authenticated mentor and a student, optionally '
            .'scoped to a project. The connection is created with status `pending`. Only a verified '
            .'mentor may create connections (403, code MENTOR_ONLY). When a `project_id` is supplied '
            .'the project must exist (404, code PROJECT_NOT_FOUND) and pass availability (422, codes '
            .'PROJECT_NOT_AVAILABLE / PROJECT_DEADLINE_PASSED / PROJECT_CAPACITY_REACHED) and '
            .'eligibility (422, code PROJECT_ELIGIBILITY_FAILED) checks. Duplicate connections are '
            .'rejected with 422 (code DUPLICATE_CONNECTION). Protected by auth:sanctum + account.active '
            .'+ mentor.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['student_id'],
                properties: [
                    new OA\Property(property: 'student_id', type: 'integer', description: 'Must reference an existing user.', example: 42),
                    new OA\Property(property: 'project_id', type: 'integer', nullable: true, description: 'Optional project to scope the connection to; must reference an existing project.', example: 55),
                    new OA\Property(property: 'initiated_by', type: 'string', nullable: true, enum: ['mentor', 'student', 'admin'], example: 'mentor'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Connection created (status `pending`).',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Connection'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Only verified mentors can create connections (code MENTOR_ONLY).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'The specified project does not exist (code PROJECT_NOT_FOUND).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 422,
                description: 'Validation error, missing student profile (STUDENT_PROFILE_NOT_FOUND), duplicate connection (DUPLICATE_CONNECTION), or a project availability/eligibility failure (PROJECT_NOT_AVAILABLE, PROJECT_DEADLINE_PASSED, PROJECT_CAPACITY_REACHED, PROJECT_ELIGIBILITY_FAILED).',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
        ],
    )]
    public function mentorConnect(): void {}

    #[OA\Get(
        path: '/v1/mentor/connections',
        operationId: 'mentorConnections',
        tags: ['Mentor'],
        summary: "List the mentor's connections",
        description: 'Returns the authenticated mentor\'s connections, newest first, paginated at 20 per '
            .'page, with each connection\'s student profile and project eager-loaded. Protected by '
            .'auth:sanctum + account.active + mentor.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Connections retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Connections retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Connection'),
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
            new OA\Response(response: 403, description: 'The caller is not a verified mentor.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function mentorConnections(): void {}

    #[OA\Patch(
        path: '/v1/mentor/connections/{connection}',
        operationId: 'mentorUpdateConnection',
        tags: ['Mentor'],
        summary: 'Update a connection status',
        description: 'Accepts, disconnects or archives a connection. Setting the status to '
            .'`disconnected` also records `disconnected_reason` and `disconnected_at`. The other party '
            .'is notified for `active` and `disconnected` transitions. Returns 404 when the connection '
            .'id does not exist. Protected by auth:sanctum + account.active + mentor.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'connection',
                in: 'path',
                required: true,
                description: 'Connection id.',
                schema: new OA\Schema(type: 'integer', example: 17),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['active', 'disconnected', 'archived'], example: 'active'),
                    new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 500, description: 'Optional reason; stored as the disconnected reason when status is `disconnected`.', example: 'Project completed.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Connection updated.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Connection'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'The caller is not a verified mentor.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'The specified connection does not exist.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error (missing/invalid `status`, or `reason` longer than 500 characters).', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function mentorUpdateConnection(): void {}
}
