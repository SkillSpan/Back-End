<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI documentation for:
 *  - App\Http\Controllers\Api\ProfileController
 *  - App\Http\Controllers\Api\ReferenceController
 *  - App\Http\Controllers\Api\SkillsController
 *
 * Paths are relative to the `/api` server, so they carry the `/v1` prefix
 * exactly as registered in routes/api.php.
 */
class ProfileReferenceSkillsEndpoints
{
    #[OA\Post(
        path: '/v1/profile',
        operationId: 'profileStore',
        tags: ['Learner Profile'],
        summary: 'Create or save the authenticated learner profile (upsert)',
        description: 'Saves the learner\'s student profile (SRS PROF-01 / UC-02). A StudentProfile row is '
            .'created at registration, so POST behaves as an upsert: it fills the existing row or creates one '
            .'if missing. It never rejects an existing profile, so the client can always POST the full data. '
            .'Returns 201 when a new row is created and 200 when an existing row is updated. The profile is '
            .'always resolved from the authenticated user — there is no {user} route param. Protected by '
            .'auth:sanctum + account.active + role:learner.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['university_name', 'student_university_number', 'specialization', 'academic_level'],
                properties: [
                    new OA\Property(property: 'university_name', type: 'string', maxLength: 191, example: 'University of Jordan'),
                    new OA\Property(property: 'student_university_number', type: 'string', maxLength: 64, example: '20211234'),
                    new OA\Property(property: 'specialization', type: 'string', maxLength: 191, example: 'Computer Science'),
                    new OA\Property(property: 'academic_level', type: 'string', maxLength: 100, example: 'Third Year'),
                    new OA\Property(property: 'expected_graduation', type: 'integer', nullable: true, minimum: 2024, maximum: 2150, example: 2027),
                    new OA\Property(property: 'bio', type: 'string', nullable: true, maxLength: 2000, example: 'Backend developer passionate about distributed systems.'),
                    new OA\Property(property: 'career_status', type: 'string', nullable: true, maxLength: 100, example: 'student'),
                    new OA\Property(
                        property: 'interests',
                        type: 'array',
                        nullable: true,
                        items: new OA\Items(type: 'string', maxLength: 100),
                        example: ['backend', 'machine learning'],
                    ),
                    new OA\Property(property: 'availability', type: 'string', nullable: true, maxLength: 100, example: 'weekdays_evening'),
                    new OA\Property(property: 'weekly_availability_hours', type: 'number', format: 'float', nullable: true, minimum: 0, maximum: 168, example: 20),
                    new OA\Property(property: 'preferred_work_type', type: 'string', nullable: true, maxLength: 100, example: 'remote'),
                    new OA\Property(property: 'visibility', type: 'string', nullable: true, enum: ['public', 'organization_only', 'private'], example: 'private'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Existing profile updated.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Learner profile saved successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/StudentProfile'),
                    ],
                ),
            ),
            new OA\Response(
                response: 201,
                description: 'New profile created.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Learner profile saved successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/StudentProfile'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated account is not a learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function profileStore(): void {}

    #[OA\Get(
        path: '/v1/profile',
        operationId: 'profileShow',
        tags: ['Learner Profile'],
        summary: 'Retrieve the authenticated learner profile',
        description: 'Returns the learner\'s own StudentProfile. Returns 404 when no profile row exists for the '
            .'account yet. Protected by auth:sanctum + account.active + role:learner.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Profile retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Profile retrieved successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/StudentProfile'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated account is not a learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 404,
                description: 'No profile exists for this account yet.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'No profile exists for this account yet.'),
                    ],
                ),
            ),
        ],
    )]
    public function profileShow(): void {}

    #[OA\Put(
        path: '/v1/profile',
        operationId: 'profileUpdate',
        tags: ['Learner Profile'],
        summary: 'Update the authenticated learner profile',
        description: 'Partially updates the learner\'s profile. Every field uses \'sometimes\', so a field that '
            .'is omitted is left untouched; a field sent as null is treated as an explicit "clear this field". '
            .'Changing primary_career_role_id closes the previous career-goal history entry, opens a new one and '
            .'dispatches a skill-data-changed event for intelligence recalculation. Returns 404 when no profile '
            .'exists yet. Protected by auth:sanctum + account.active + role:learner.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'university_name', type: 'string', nullable: true, maxLength: 191, example: 'University of Jordan'),
                    new OA\Property(property: 'student_university_number', type: 'string', nullable: true, maxLength: 64, example: '20211234'),
                    new OA\Property(property: 'specialization', type: 'string', nullable: true, maxLength: 191, example: 'Computer Science'),
                    new OA\Property(property: 'academic_level', type: 'string', nullable: true, maxLength: 100, example: 'Fourth Year'),
                    new OA\Property(property: 'expected_graduation', type: 'integer', nullable: true, minimum: 2024, maximum: 2150, example: 2027),
                    new OA\Property(property: 'bio', type: 'string', nullable: true, maxLength: 2000, example: 'Backend developer passionate about distributed systems.'),
                    new OA\Property(property: 'career_status', type: 'string', nullable: true, maxLength: 100, example: 'student'),
                    new OA\Property(
                        property: 'interests',
                        type: 'array',
                        nullable: true,
                        items: new OA\Items(type: 'string', maxLength: 100),
                        example: ['backend', 'machine learning'],
                    ),
                    new OA\Property(property: 'availability', type: 'string', nullable: true, maxLength: 100, example: 'weekdays_evening'),
                    new OA\Property(property: 'weekly_availability_hours', type: 'number', format: 'float', nullable: true, minimum: 0, maximum: 168, example: 20),
                    new OA\Property(property: 'preferred_work_type', type: 'string', nullable: true, maxLength: 100, example: 'remote'),
                    new OA\Property(property: 'primary_career_role_id', type: 'integer', nullable: true, example: 7, description: 'Must reference an existing career role whose status is "approved".'),
                    new OA\Property(property: 'visibility', type: 'string', nullable: true, enum: ['public', 'organization_only', 'private'], example: 'private'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Profile updated.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Profile updated successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/StudentProfile'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated account is not a learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 404,
                description: 'No profile exists for this account yet.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'No profile exists for this account yet. Use POST /api/v1/profile to create one first.'),
                    ],
                ),
            ),
            new OA\Response(response: 422, description: 'Validation error.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function profileUpdate(): void {}

    #[OA\Get(
        path: '/v1/reference/universities',
        operationId: 'referenceUniversities',
        tags: ['Reference Data'],
        summary: 'List active universities',
        description: 'Public reference data used to populate onboarding dropdowns (no authentication required). '
            .'Returns every active university ordered by name, exposing only id and name.',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Universities retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Universities retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'name', type: 'string', example: 'University of Jordan'),
                                ],
                            ),
                        ),
                    ],
                ),
            ),
        ],
    )]
    public function referenceUniversities(): void {}

    #[OA\Get(
        path: '/v1/reference/specializations',
        operationId: 'referenceSpecializations',
        tags: ['Reference Data'],
        summary: 'List active specializations',
        description: 'Public reference data used to populate onboarding dropdowns (no authentication required). '
            .'Returns every active specialization ordered by name, exposing only id and name.',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Specializations retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Specializations retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'name', type: 'string', example: 'Computer Science'),
                                ],
                            ),
                        ),
                    ],
                ),
            ),
        ],
    )]
    public function referenceSpecializations(): void {}

    #[OA\Get(
        path: '/v1/reference/countries',
        operationId: 'referenceCountries',
        tags: ['Reference Data'],
        summary: 'List countries',
        description: 'Public reference data backing the onboarding country dropdown (no authentication required). '
            .'Returns all countries ordered alphabetically by English name so the list is stable across locales.',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Countries retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Countries retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'name', type: 'string', example: 'Jordan'),
                                    new OA\Property(property: 'name_ar', type: 'string', example: 'الأردن'),
                                    new OA\Property(property: 'iso2', type: 'string', example: 'JO'),
                                    new OA\Property(property: 'iso3', type: 'string', example: 'JOR'),
                                ],
                            ),
                        ),
                    ],
                ),
            ),
        ],
    )]
    public function referenceCountries(): void {}

    #[OA\Get(
        path: '/v1/reference/countries/{country}/universities',
        operationId: 'referenceCountryUniversities',
        tags: ['Reference Data'],
        summary: 'List active universities of a country',
        description: 'Dependent-select reference data (no authentication required). Returns only the active '
            .'universities belonging to the given country, ordered by name, exposing id and name. The {country} '
            .'segment is resolved by route-model binding; an unknown id yields a 404.',
        parameters: [
            new OA\Parameter(
                name: 'country',
                in: 'path',
                required: true,
                description: 'Country id.',
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Universities retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Universities retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'name', type: 'string', example: 'University of Jordan'),
                                ],
                            ),
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 404, description: 'Country not found.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function referenceCountryUniversities(): void {}

    #[OA\Get(
        path: '/v1/skills/taxonomy',
        operationId: 'skillsTaxonomy',
        tags: ['Skills'],
        summary: 'Retrieve the skills taxonomy',
        description: 'Returns all active skills with their taxonomy information (aliases, parent, children and '
            .'related career roles). Protected by auth:sanctum + account.active.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Skills taxonomy retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Skills taxonomy retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Skill'),
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function skillsTaxonomy(): void {}

    #[OA\Get(
        path: '/v1/skills/matrix',
        operationId: 'skillsMatrixIndex',
        tags: ['Skills'],
        summary: 'Retrieve the skill matrix for a learner',
        description: 'Returns learner skill rows. Non-admin callers always receive their own matrix; only an '
            .'administrator may query another learner\'s matrix via learner_id. career_role_id filters to skills '
            .'linked to that career role. Protected by auth:sanctum + account.active.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'learner_id',
                in: 'query',
                required: false,
                description: 'Target learner id. Only honoured for administrators; ignored for other callers.',
                schema: new OA\Schema(type: 'integer', example: 42),
            ),
            new OA\Parameter(
                name: 'career_role_id',
                in: 'query',
                required: false,
                description: 'Filter to skills linked to this career role.',
                schema: new OA\Schema(type: 'integer', example: 7),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Skill matrix retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Skill matrix retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/LearnerSkill'),
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function skillsMatrixIndex(): void {}

    #[OA\Post(
        path: '/v1/skills/matrix',
        operationId: 'skillsMatrixStore',
        tags: ['Skills'],
        summary: 'Assign or update a learner skill',
        description: 'Creates or updates a learner skill row (matched on learner_id + skill_id). A non-admin '
            .'caller may only write their own rows; attempting to write another learner\'s row returns 403. '
            .'source_contributions is a structured object/array, not a JSON-encoded string. Protected by '
            .'auth:sanctum + account.active.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['learner_id', 'skill_id', 'level', 'confidence_score'],
                properties: [
                    new OA\Property(property: 'learner_id', type: 'integer', example: 42, description: 'Must exist in users.id.'),
                    new OA\Property(property: 'skill_id', type: 'integer', example: 15, description: 'Must exist in skills.id.'),
                    new OA\Property(property: 'level', type: 'number', format: 'float', minimum: 0, maximum: 5, example: 3.5),
                    new OA\Property(property: 'confidence_score', type: 'number', format: 'float', minimum: 0, maximum: 100, example: 80.0),
                    new OA\Property(property: 'source_type', type: 'string', nullable: true, example: 'self_assessment'),
                    new OA\Property(property: 'algorithm_version', type: 'string', nullable: true, example: 'v1.2'),
                    new OA\Property(property: 'configuration_version', type: 'string', nullable: true, example: '2024-01'),
                    new OA\Property(
                        property: 'source_contributions',
                        type: 'object',
                        nullable: true,
                        example: ['self_assessment' => 0.6, 'evidence' => 0.4],
                        description: 'Structured payload (array/object), not a JSON string.',
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Skill assigned or updated.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Skill assigned/updated successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/LearnerSkill'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'Attempted to modify another learner\'s skills.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'You are not authorized to modify another learner\'s skills.'),
                    ],
                ),
            ),
            new OA\Response(response: 422, description: 'Validation error.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function skillsMatrixStore(): void {}

    #[OA\Put(
        path: '/v1/skills/matrix/{id}',
        operationId: 'skillsMatrixUpdate',
        tags: ['Skills'],
        summary: 'Update a specific learner skill record',
        description: 'Updates the given learner skill row. All fields are optional (\'sometimes\'). A non-admin '
            .'caller can only see and update their own rows; another learner\'s id is treated as not found (404) '
            .'rather than 403, so the endpoint does not confirm the id exists. calculated_at is server-managed '
            .'and cannot be set by the client. Protected by auth:sanctum + account.active.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'Learner skill row id.',
                schema: new OA\Schema(type: 'integer', example: 101),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'level', type: 'number', format: 'float', minimum: 0, maximum: 5, example: 4.0),
                    new OA\Property(property: 'confidence_score', type: 'number', format: 'float', minimum: 0, maximum: 100, example: 90.0),
                    new OA\Property(property: 'source_type', type: 'string', nullable: true, example: 'self_assessment'),
                    new OA\Property(property: 'algorithm_version', type: 'string', nullable: true, example: 'v1.2'),
                    new OA\Property(property: 'configuration_version', type: 'string', nullable: true, example: '2024-01'),
                    new OA\Property(
                        property: 'source_contributions',
                        type: 'object',
                        nullable: true,
                        example: ['self_assessment' => 0.6, 'evidence' => 0.4],
                        description: 'Structured payload (array/object), not a JSON string.',
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Skill updated.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Skill updated successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/LearnerSkill'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Learner skill row not found (or not visible to the caller).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function skillsMatrixUpdate(): void {}
}
