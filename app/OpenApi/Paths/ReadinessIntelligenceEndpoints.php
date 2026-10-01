<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI documentation for the readiness, intelligence, baseline-assessment
 * and assistant endpoints:
 *   App\Http\Controllers\Api\ReadinessController
 *   App\Http\Controllers\Api\SkillMatchController
 *   App\Http\Controllers\Api\IntelligenceController
 *   App\Http\Controllers\Api\BaselineAssessmentController
 *   App\Http\Controllers\Api\AssistantController
 *
 * Paths are relative to the `/api` server, so they carry the `/v1` prefix
 * exactly as registered in routes/api.php. Every operation is protected by
 * `auth:sanctum` + `account.active` + `role:learner`.
 */
class ReadinessIntelligenceEndpoints
{
    #[OA\Post(
        path: '/v1/readiness/calculate',
        operationId: 'readinessCalculate',
        tags: ['Readiness'],
        summary: 'Calculate career readiness for the authenticated learner',
        description: 'Runs the composite readiness algorithm (skill match, practical experience, '
            .'assessment reliability, profile completeness) for the learner\'s own student profile and '
            .'persists a decision snapshot. When `career_role_id` is omitted the learner\'s target role '
            .'is used. Requires the `learner` role.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'career_role_id', type: 'integer', nullable: true, example: 7, description: 'Optional career role to score against; must be greater than 0.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Readiness calculated.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/ReadinessResult'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated user is not a learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'No student profile (`STUDENT_PROFILE_NOT_FOUND`), a readiness domain error (`ReadinessException`), or a validation failure.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 500, description: 'Calculation failed (`READINESS_CALCULATION_FAILED`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function readinessCalculate(): void {}

    #[OA\Get(
        path: '/v1/readiness/latest',
        operationId: 'readinessLatest',
        tags: ['Readiness'],
        summary: 'Retrieve the latest readiness result for the authenticated learner',
        description: 'Returns the most recently stored readiness result for the learner\'s own student '
            .'profile, optionally narrowed to a single career role. Returns 404 when the learner has no '
            .'readiness result yet. Requires the `learner` role.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'career_role_id',
                in: 'query',
                required: false,
                description: 'Only return the result for this career role.',
                schema: new OA\Schema(type: 'integer', example: 7),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Latest readiness result.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/ReadinessResult'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated user is not a learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'No readiness result available (`READINESS_NOT_FOUND`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'No student profile (`STUDENT_PROFILE_NOT_FOUND`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 500, description: 'Lookup failed (`READINESS_LOOKUP_FAILED`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function readinessLatest(): void {}

    #[OA\Post(
        path: '/v1/skill-match',
        operationId: 'skillMatchStore',
        tags: ['Readiness'],
        summary: 'Run the deterministic skill-match algorithm',
        description: 'Scores a caller-supplied skill set against a career role using the `skill-match-v1` '
            .'algorithm. The payload is a pure function input (no database access, no current date, no '
            .'randomness). Duplicate `skill_id` values or a non-positive total importance weight are '
            .'rejected. Requires the `learner` role.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['student_profile_id', 'career_role_id', 'career_role_version', 'user_id', 'target_role', 'skills'],
                properties: [
                    new OA\Property(property: 'student_profile_id', type: 'integer', minimum: 1, example: 12),
                    new OA\Property(property: 'career_role_id', type: 'integer', minimum: 1, example: 7),
                    new OA\Property(property: 'career_role_version', type: 'integer', minimum: 1, example: 3),
                    new OA\Property(property: 'user_id', type: 'integer', minimum: 1, example: 42),
                    new OA\Property(property: 'target_role', type: 'string', minLength: 2, maxLength: 100, example: 'Backend Developer'),
                    new OA\Property(
                        property: 'skills',
                        type: 'array',
                        minItems: 1,
                        items: new OA\Items(
                            type: 'object',
                            required: ['skill_id', 'skill_name', 'current_level', 'required_level', 'importance_weight', 'is_critical'],
                            properties: [
                                new OA\Property(property: 'skill_id', type: 'integer', minimum: 1, example: 101),
                                new OA\Property(property: 'skill_name', type: 'string', minLength: 2, maxLength: 100, example: 'PHP'),
                                new OA\Property(property: 'current_level', type: 'number', format: 'float', minimum: 0, maximum: 5, example: 3),
                                new OA\Property(property: 'required_level', type: 'number', format: 'float', minimum: 0, maximum: 5, example: 4),
                                new OA\Property(property: 'importance_weight', type: 'number', format: 'float', exclusiveMinimum: 0, maximum: 1, example: 0.4),
                                new OA\Property(property: 'is_critical', type: 'boolean', example: true),
                            ],
                        ),
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Skill-match result.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'student_profile_id', type: 'integer', example: 12),
                        new OA\Property(property: 'career_role_id', type: 'integer', example: 7),
                        new OA\Property(property: 'career_role_version', type: 'integer', example: 3),
                        new OA\Property(property: 'user_id', type: 'integer', example: 42),
                        new OA\Property(property: 'target_role', type: 'string', example: 'Backend Developer'),
                        new OA\Property(property: 'algorithm_version', type: 'string', example: 'skill-match-v1'),
                        new OA\Property(property: 'weight_configuration_version', type: 'string', example: 'career-role-config-v1'),
                        new OA\Property(property: 'original_weight_total', type: 'number', format: 'float', example: 1.0),
                        new OA\Property(property: 'normalized_weight_total', type: 'number', format: 'float', example: 1.0),
                        new OA\Property(property: 'weighted_achieved_total', type: 'number', format: 'float', example: 3.25),
                        new OA\Property(property: 'weighted_required_total', type: 'number', format: 'float', example: 3.8),
                        new OA\Property(property: 'skill_match_score', type: 'number', format: 'float', example: 85.53),
                        new OA\Property(property: 'total_skills', type: 'integer', example: 5),
                        new OA\Property(property: 'met_skills', type: 'integer', example: 3),
                        new OA\Property(property: 'partial_skills', type: 'integer', example: 1),
                        new OA\Property(property: 'not_required_skills', type: 'integer', example: 1),
                        new OA\Property(
                            property: 'skill_results',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'skill_id', type: 'integer', example: 101),
                                    new OA\Property(property: 'skill_name', type: 'string', example: 'PHP'),
                                    new OA\Property(property: 'current_level', type: 'number', format: 'float', example: 3),
                                    new OA\Property(property: 'required_level', type: 'number', format: 'float', example: 4),
                                    new OA\Property(property: 'importance_weight', type: 'number', format: 'float', example: 0.4),
                                    new OA\Property(property: 'normalized_importance_weight', type: 'number', format: 'float', example: 0.4),
                                    new OA\Property(property: 'achieved_level', type: 'number', format: 'float', example: 3),
                                    new OA\Property(property: 'match_ratio', type: 'number', format: 'float', example: 0.75),
                                    new OA\Property(property: 'status', type: 'string', enum: ['met', 'partial', 'not_required'], example: 'partial'),
                                ],
                            ),
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated user is not a learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 422,
                description: 'Skill-match domain error (`INVALID_WEIGHT_TOTAL` or `DUPLICATE_SKILL_ID`), or a validation failure.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'detail',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'code', type: 'string', example: 'DUPLICATE_SKILL_ID'),
                                new OA\Property(property: 'message', type: 'string', example: 'Skill Match input must not contain duplicate skill_id values.'),
                            ],
                        ),
                    ],
                ),
            ),
        ],
    )]
    public function skillMatchStore(): void {}

    #[OA\Post(
        path: '/v1/intelligence/calculate',
        operationId: 'intelligenceCalculate',
        tags: ['Intelligence'],
        summary: 'Run the combined intelligence decision',
        description: 'Produces one atomic decision combining skill gaps, readiness and a roadmap for the '
            .'learner\'s own student profile, and persists a decision snapshot. When `career_role_id` is '
            .'omitted the learner\'s target role is used. Returns 201 because a decision resource is '
            .'created. Requires the `learner` role.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'career_role_id', type: 'integer', nullable: true, example: 7, description: 'Optional career role to score against; must be greater than 0.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Intelligence decision created.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/IntelligenceResult'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated user is not a learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'No student profile (`STUDENT_PROFILE_NOT_FOUND`), a readiness domain error, or a validation failure.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 500, description: 'Calculation failed (`INTELLIGENCE_CALCULATION_FAILED`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function intelligenceCalculate(): void {}

    #[OA\Get(
        path: '/v1/intelligence/latest',
        operationId: 'intelligenceLatest',
        tags: ['Intelligence'],
        summary: 'Retrieve the latest intelligence decision',
        description: 'Returns the newest succeeded intelligence-flow decision (readiness + skill gaps + '
            .'roadmap) for the learner\'s own student profile, optionally narrowed to a single career '
            .'role. Legacy readiness-only snapshots are excluded. Returns 404 when none exists. Requires '
            .'the `learner` role.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'career_role_id',
                in: 'query',
                required: false,
                description: 'Only return the decision for this career role.',
                schema: new OA\Schema(type: 'integer', example: 7),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Latest intelligence decision.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/IntelligenceResult'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated user is not a learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'No successful intelligence decision available (`DECISION_NOT_FOUND`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'No student profile (`STUDENT_PROFILE_NOT_FOUND`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function intelligenceLatest(): void {}

    #[OA\Post(
        path: '/v1/baseline-assessments',
        operationId: 'baselineStart',
        tags: ['Baseline Assessment'],
        summary: 'Start a baseline assessment',
        description: 'Creates a new baseline assessment for the learner\'s own student profile against an '
            .'approved career role, freezing the selected question set into an immutable snapshot. '
            .'Requires the `learner` role.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['career_role_id'],
                properties: [
                    new OA\Property(property: 'career_role_id', type: 'integer', example: 7, description: 'Must exist and have status `approved`.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Baseline assessment started.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Baseline assessment started.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/BaselineAssessment'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated user is not a learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'No student profile (`STUDENT_PROFILE_NOT_FOUND`), unknown/unapproved career role, or a validation failure.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 500, description: 'Assessment could not be started (`BASELINE_ASSESSMENT_FAILED`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function baselineStart(): void {}

    #[OA\Get(
        path: '/v1/baseline-assessments/{assessment}',
        operationId: 'baselineShow',
        tags: ['Baseline Assessment'],
        summary: 'Retrieve a baseline assessment',
        description: 'Returns the frozen question set and current state of one of the learner\'s own '
            .'baseline assessments. Grading keys (`correct_answer`, `scoring_rule`) are never exposed, '
            .'and `responses` is only returned once the assessment is completed. Requires the `learner` '
            .'role.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'assessment',
                in: 'path',
                required: true,
                description: 'Baseline assessment id.',
                schema: new OA\Schema(type: 'integer', example: 55),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Baseline assessment retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Baseline assessment retrieved successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/BaselineAssessment'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated user is not a learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Assessment does not exist or is not owned by the learner (`ASSESSMENT_NOT_FOUND`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function baselineShow(): void {}

    #[OA\Patch(
        path: '/v1/baseline-assessments/{assessment}',
        operationId: 'baselineProgress',
        tags: ['Baseline Assessment'],
        summary: 'Save baseline assessment progress',
        description: 'Persists partial progress and/or responses for one of the learner\'s own baseline '
            .'assessments without completing it. Both fields are optional. Requires the `learner` role.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'assessment',
                in: 'path',
                required: true,
                description: 'Baseline assessment id.',
                schema: new OA\Schema(type: 'integer', example: 55),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'progress', type: 'object', nullable: true, additionalProperties: true, example: ['answered' => 3, 'total' => 10]),
                    new OA\Property(property: 'responses', type: 'object', nullable: true, additionalProperties: true, example: ['q_001' => 4]),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Progress saved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Baseline assessment progress saved.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/BaselineAssessment'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated user is not a learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Assessment does not exist or is not owned by the learner (`ASSESSMENT_NOT_FOUND`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'A baseline-assessment domain error or a validation failure.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 500, description: 'Progress could not be saved (`BASELINE_ASSESSMENT_FAILED`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function baselineProgress(): void {}

    #[OA\Post(
        path: '/v1/baseline-assessments/{assessment}/submit',
        operationId: 'baselineSubmit',
        tags: ['Baseline Assessment'],
        summary: 'Submit and complete a baseline assessment',
        description: 'Submits the learner\'s answers for one of their own baseline assessments, completing '
            .'it and computing the result. At least one response is required; each response must carry a '
            .'`question_id` and an `answer`. Requires the `learner` role.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'assessment',
                in: 'path',
                required: true,
                description: 'Baseline assessment id.',
                schema: new OA\Schema(type: 'integer', example: 55),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['responses'],
                properties: [
                    new OA\Property(
                        property: 'responses',
                        type: 'array',
                        minItems: 1,
                        description: 'A non-empty JSON array of answer objects.',
                        items: new OA\Items(
                            type: 'object',
                            required: ['question_id', 'answer'],
                            properties: [
                                new OA\Property(property: 'question_id', type: 'string', maxLength: 100, example: 'q_001'),
                                new OA\Property(property: 'answer', description: 'The learner\'s answer; type depends on the question.', example: 4),
                            ],
                        ),
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Assessment completed.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Baseline assessment completed successfully.'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/BaselineAssessment'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated user is not a learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Assessment does not exist or is not owned by the learner (`ASSESSMENT_NOT_FOUND`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Missing/empty responses, a baseline-assessment domain error, or a validation failure.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 500, description: 'Assessment could not be completed (`BASELINE_ASSESSMENT_FAILED`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function baselineSubmit(): void {}

    #[OA\Post(
        path: '/v1/assistant/ask',
        operationId: 'assistantAsk',
        tags: ['Assistant'],
        summary: 'Ask the intelligent assistant about the learner\'s own stored decisions',
        description: 'Sends a scoped question about the learner\'s own readiness, skill gaps, roadmap, next '
            .'best action, project recommendation or project help. The `intent` must be one of the '
            .'permitted scope values; anything else is rejected with `ASSISTANT_INTENT_NOT_ALLOWED`. The '
            .'assistant reply is relayed but never persisted. Returns 201 because an interaction resource '
            .'is created. Requires the `learner` role.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['intent', 'question'],
                properties: [
                    new OA\Property(
                        property: 'intent',
                        type: 'string',
                        enum: [
                            'explain_readiness',
                            'explain_skill_gap',
                            'explain_roadmap',
                            'explain_next_best_action',
                            'explain_project_recommendation',
                            'project_bounded_help',
                        ],
                        example: 'explain_readiness',
                    ),
                    new OA\Property(property: 'question', type: 'string', minLength: 3, maxLength: 2000, example: 'Why is my readiness score in the developing band?'),
                    new OA\Property(property: 'recommendation_id', type: 'integer', nullable: true, example: 321, description: 'Optional; shape-checked only.'),
                    new OA\Property(property: 'project_id', type: 'integer', nullable: true, example: 88, description: 'Optional; shape-checked only.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Assistant interaction recorded and a reply returned.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 9001),
                                new OA\Property(property: 'intent', type: 'string', example: 'explain_readiness'),
                                new OA\Property(property: 'context_reference', type: 'string', nullable: true, example: 'decision:3f1a-…'),
                                new OA\Property(property: 'related_recommendation_id', type: 'integer', nullable: true, example: 321),
                                new OA\Property(property: 'related_project_id', type: 'integer', nullable: true, example: 88),
                                new OA\Property(property: 'response_status', type: 'string', enum: ['pending', 'succeeded', 'failed'], example: 'succeeded'),
                                new OA\Property(property: 'report_status', type: 'string', nullable: true, example: null),
                                new OA\Property(property: 'report_reason', type: 'string', nullable: true, example: null),
                                new OA\Property(property: 'reported_at', type: 'string', format: 'date-time', nullable: true, example: null),
                                new OA\Property(property: 'algorithm_version', type: 'string', nullable: true, example: 'assistant-v1'),
                                new OA\Property(property: 'prompt_version', type: 'string', nullable: true, example: 'prompt-v1'),
                                new OA\Property(property: 'configuration_version', type: 'string', nullable: true, example: 'config-v1'),
                                new OA\Property(property: 'failure_code', type: 'string', nullable: true, example: null),
                                new OA\Property(property: 'request_id', type: 'string', nullable: true, example: '9b1c2d3e-4f50-4a6b-8c7d-8e9f0a1b2c3d'),
                                new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true, example: '2026-02-01T10:15:30+00:00'),
                                new OA\Property(property: 'reply', type: 'string', example: 'Your readiness score is driven mainly by the skill-match component, which is capped by two critical skills.'),
                                new OA\Property(property: 'provider_used', type: 'string', example: 'fastapi'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated user is not a learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Intent outside the permitted scope (`ASSISTANT_INTENT_NOT_ALLOWED`), no student profile, or a validation failure.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 500, description: 'Assistant request could not be completed (`ASSISTANT_FAILED`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function assistantAsk(): void {}

    #[OA\Put(
        path: '/v1/assistant/interactions/{interaction}/report',
        operationId: 'assistantReport',
        tags: ['Assistant'],
        summary: 'Report an assistant interaction',
        description: 'Records the learner\'s classification of one of their own assistant interactions as '
            .'unsafe, irrelevant, unfair or incorrect, with an optional reason. Ownership is enforced by a '
            .'scoped lookup, so a non-owned id is indistinguishable from a non-existent one. Requires the '
            .'`learner` role.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'interaction',
                in: 'path',
                required: true,
                description: 'Assistant interaction id.',
                schema: new OA\Schema(type: 'integer', example: 9001),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['report_status'],
                properties: [
                    new OA\Property(property: 'report_status', type: 'string', enum: ['unsafe', 'irrelevant', 'unfair', 'incorrect'], example: 'unsafe'),
                    new OA\Property(property: 'report_reason', type: 'string', nullable: true, maxLength: 1000, example: 'The reply recommended a skill I already have.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Interaction reported.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 9001),
                                new OA\Property(property: 'intent', type: 'string', example: 'explain_readiness'),
                                new OA\Property(property: 'context_reference', type: 'string', nullable: true, example: 'decision:3f1a-…'),
                                new OA\Property(property: 'related_recommendation_id', type: 'integer', nullable: true, example: null),
                                new OA\Property(property: 'related_project_id', type: 'integer', nullable: true, example: null),
                                new OA\Property(property: 'response_status', type: 'string', enum: ['pending', 'succeeded', 'failed'], example: 'succeeded'),
                                new OA\Property(property: 'report_status', type: 'string', nullable: true, example: 'unsafe'),
                                new OA\Property(property: 'report_reason', type: 'string', nullable: true, example: 'The reply recommended a skill I already have.'),
                                new OA\Property(property: 'reported_at', type: 'string', format: 'date-time', nullable: true, example: '2026-02-01T11:00:00+00:00'),
                                new OA\Property(property: 'algorithm_version', type: 'string', nullable: true, example: 'assistant-v1'),
                                new OA\Property(property: 'prompt_version', type: 'string', nullable: true, example: 'prompt-v1'),
                                new OA\Property(property: 'configuration_version', type: 'string', nullable: true, example: 'config-v1'),
                                new OA\Property(property: 'failure_code', type: 'string', nullable: true, example: null),
                                new OA\Property(property: 'request_id', type: 'string', nullable: true, example: '9b1c2d3e-4f50-4a6b-8c7d-8e9f0a1b2c3d'),
                                new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true, example: '2026-02-01T10:15:30+00:00'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Authenticated user is not a learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Interaction does not exist or is not owned by the learner.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Unknown report classification, no student profile, or a validation failure.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 500, description: 'Interaction could not be reported (`ASSISTANT_REPORT_FAILED`).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function assistantReport(): void {}
}
