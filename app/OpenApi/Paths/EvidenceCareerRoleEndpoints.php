<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

class EvidenceCareerRoleEndpoints
{
    #[OA\Post(
        path: '/v1/evidence',
        operationId: 'evidenceStore',
        tags: ['Evidence'],
        summary: 'Submit evidence for a skill',
        description: 'Creates a new evidence record for the authenticated learner, linked to an active skill. '
            .'The record is stored as `pending` until an administrator reviews it. '
            ."\n\n".'**Evidence types.** `evidence_type` declares what the proof is and therefore which half of the '
            .'payload is required: `github_repository`, `live_deployment` and `design_file` require `evidence_url` '
            .'and refuse `evidence_file`; `document` requires `evidence_file` (pdf, jpg, jpeg, png, webp, max '
            .'10 MB) and refuses `evidence_url`. The unused half is rejected rather than ignored, so a 201 always '
            .'means the proof was actually stored. '
            ."\n\n".'**Legacy requests.** `evidence_type` is optional. Omitting it keeps the previous contract — at '
            .'least one of `evidence_url` / `evidence_file` — and stores `evidence_type: null` ("type not '
            .'recorded"), except that a lone file upload is recorded as `document`. '
            ."\n\n".'**URL rules.** Only http/https, publicly reachable hosts (no loopback or private ranges). '
            .'`github_repository` must be an https github.com URL with an `/{owner}/{repo}` path. '
            ."\n\n".'**Duplicates.** The same learner + skill + evidence type + reference is rejected with 409. '
            ."\n\n".'`source` is NOT the evidence type: it is the weighted skill-evaluation channel and stays '
            .'`certificate` for every learner submission.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['skill_id'],
                    properties: [
                        new OA\Property(property: 'skill_id', type: 'integer', description: 'Must reference an existing skill whose status is `active`.', example: 15),
                        new OA\Property(
                            property: 'evidence_type',
                            type: 'string',
                            nullable: true,
                            enum: ['github_repository', 'live_deployment', 'design_file', 'document'],
                            description: 'Optional. Declares the kind of proof and which payload half is required. Omit it to use the legacy url-or-file contract. Any other value is a 422. Case and surrounding whitespace are normalised.',
                            example: 'github_repository'
                        ),
                        new OA\Property(property: 'evidence_url', type: 'string', format: 'uri', nullable: true, maxLength: 255, description: 'Required for `github_repository`, `live_deployment` and `design_file`; refused for `document`. For a legacy request (no `evidence_type`), required when `evidence_file` is not sent. Must be http/https on a publicly reachable host; `github_repository` additionally requires https on github.com with an `/{owner}/{repo}` path.', example: 'https://github.com/user/project'),
                        new OA\Property(property: 'evidence_file', type: 'string', format: 'binary', nullable: true, description: 'Required for `document`; refused for the URL-based types. For a legacy request (no `evidence_type`), required when `evidence_url` is not sent. Allowed types: pdf, jpg, jpeg, png, webp. Maximum 10000 KB (10 MB). Stored on the private disk, never the public one.', example: 'certificate.pdf'),
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
            new OA\Response(response: 409, description: 'Duplicate evidence: the same learner, skill, evidence type and reference already exist.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error — an unknown `evidence_type`, a missing or forbidden `evidence_url`/`evidence_file`, an invalid or non-public URL, a GitHub URL that is not a repository, a disallowed or oversized upload, an inactive `skill_id` — or the account has no student profile (`success`/`message` envelope).', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function evidenceStore(): void {}

    #[OA\Get(
        path: '/v1/evidence',
        operationId: 'evidenceIndex',
        tags: ['Evidence'],
        summary: 'List the authenticated learner\'s evidence',
        description: 'Returns the authenticated learner\'s own evidence records in EVERY verification state — '
            .'`pending`, `verified`, `rejected`, `expired` and `revision` — with the related skill and reviewer '
            .'eager-loaded. The optional `status` query parameter narrows the list to a single state; an unknown '
            .'value is a 422. Records belonging to another learner are never returned: the ownership check is '
            .'part of the query.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'status',
                in: 'query',
                required: false,
                description: 'Optional filter. When present, only evidence in this state is returned.',
                schema: new OA\Schema(type: 'string', enum: ['pending', 'verified', 'rejected', 'expired', 'revision'], example: 'revision'),
            ),
        ],
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
            new OA\Response(response: 422, description: 'The account has no student profile yet, or `status` is not one of the allowed states.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
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
        description: 'Marks an evidence record as `verified`, `rejected` or `revision` (the reviewer\'s "request '
            .'clarification" outcome required by SRS SKL-05 / UC-03), records the reviewer and optional notes, '
            .'recalculates the learner\'s skill level and confidence synchronously, and dispatches a queued '
            .'intelligence recalculation for the learner. Only evidence still awaiting a decision (`pending` or '
            .'`revision`) can be reviewed; a terminal record (`verified`, `rejected`, `expired`) is refused with '
            .'`422 INVALID_EVIDENCE_TRANSITION` rather than silently rewritten (SRS BR-EVA-06).',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Evidence record id.', schema: new OA\Schema(type: 'integer'), example: 301),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['verification_status'],
                properties: [
                    new OA\Property(property: 'verification_status', type: 'string', enum: ['verified', 'rejected', 'revision'], description: '`revision` = request clarification from the learner.', example: 'verified'),
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
            new OA\Response(response: 422, description: 'Validation error (invalid or missing `verification_status`), or `INVALID_EVIDENCE_TRANSITION` when the record is no longer awaiting a decision.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function evidenceReview(): void {}

    #[OA\Get(
        path: '/v1/career-roles',
        operationId: 'careerRolesIndex',
        tags: ['Career Roles'],
        summary: 'List career roles available to learners',
        description: 'Returns every career role a learner may currently choose: `status = approved` AND '
            .'`is_active = true`. The two flags are independent — `status` is the review state of the role '
            .'definition (draft / approved / retired), while `is_active` is whether the product offers the role '
            .'right now. A role can be approved and still not offered (e.g. an out-of-MVP role), and an inactive '
            .'role is never returned here even though it stays fully readable through '
            .'`GET /v1/career-roles/{id}` and `GET /v1/career-roles/{id}/skills`. '
            .'Since the MVP, a specialization no longer RESTRICTS the list: every active specialization returns '
            .'the same set of roles, and `specialization_id` only changes the ORDER — the roles configured as '
            .'closest to that specialization come first, in their configured order, and every other active role '
            .'follows in a stable neutral order (`version` descending, then `id` descending). '
            .'The "Self-Learning / Free Track" has no priority list and therefore returns the same set in the '
            .'neutral order; omitting `specialization_id` produces exactly that neutral order too, so existing '
            .'consumers keep their previous list. '
            .'Ordering is applied before pagination and is total, so a role can never appear on two pages or be '
            .'skipped. Each item carries a `skills_count`. The response echoes (or generates) a `request_id`, '
            .'also returned in the `X-Request-ID` header.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'specialization_id',
                in: 'query',
                required: false,
                description: 'Optional. Must be a positive integer referencing an existing AND ACTIVE '
                    .'specialization. When present, the roles closest to that specialization are listed first; '
                    .'the rest of the active roles follow. It never hides a role. An inactive specialization is '
                    .'rejected with a 422 validation error. When omitted, the neutral order is returned.',
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 2),
            ),
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                required: false,
                description: 'Page size (default 15, clamped to 1..100). BA-04.',
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, example: 15),
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
                            description: 'Laravel length-aware paginator (page size `per_page`, default 15, max 100).',
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
            new OA\Response(response: 422, description: 'Validation error — `specialization_id` is not a positive integer, does not reference an existing specialization, or references an inactive one.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
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
            .'`CAREER_ROLE_NOT_APPROVED`. '
            .'A role that is approved but currently INACTIVE (`is_active = false`, i.e. not offered to learners) '
            .'is still returned: deactivating a role only removes it from the pickers, it must never lock a '
            .'learner or a project out of a role they already reference. The response echoes a `request_id`.',
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

    #[OA\Get(
        path: '/v1/saved-career-roles',
        operationId: 'savedCareerRolesIndex',
        tags: ['Career Roles'],
        summary: 'List the career roles the caller has saved',
        description: 'Returns the caller\'s saved career roles, most recently saved first. '
            ."\n\n".'`data` is a **plain JSON array**, not a paginator, so the client can render it directly. '
            .'Only roles that are still selectable (approved and active) are returned: a saved role that was '
            .'deactivated later silently leaves the list while its saved row is kept, so it comes back if the '
            .'role is reactivated. '
            ."\n\n".'The list is per account — one user never sees another user\'s saves.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Saved career roles retrieved.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Saved career roles retrieved successfully.'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            description: 'Empty array when nothing is saved.',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 12),
                                    new OA\Property(property: 'title', type: 'string', example: 'Data Analyst'),
                                    new OA\Property(property: 'slug', type: 'string', example: 'data-analyst'),
                                    new OA\Property(property: 'version', type: 'integer', example: 1),
                                    new OA\Property(property: 'status', type: 'string', example: 'approved'),
                                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Turns data into decisions.'),
                                    new OA\Property(property: 'effective_date', type: 'string', format: 'date', nullable: true, example: '2026-01-01'),
                                    new OA\Property(property: 'skills_count', type: 'integer', description: 'Total required skills, even before the skill map is opened.', example: 5),
                                    new OA\Property(property: 'is_saved', type: 'boolean', example: true),
                                    new OA\Property(property: 'saved_at', type: 'string', format: 'date-time', nullable: true, example: '2026-11-13T14:05:00+00:00'),
                                ],
                            ),
                        ),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f2504e0-4f89-41d3-9a0c-0305e82c3301'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function savedCareerRolesIndex(): void {}

    #[OA\Put(
        path: '/v1/saved-career-roles/{id}',
        operationId: 'savedCareerRolesStore',
        tags: ['Career Roles'],
        summary: 'Save a career role',
        description: 'Saves the career role for the caller. Idempotent: saving the same role twice keeps one row and '
            .'still answers 200, so a double click is harmless. '
            ."\n\n".'Only approved, active roles can be saved — a draft or retired role is refused with 422 instead of '
            .'being stored.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Career role id.', schema: new OA\Schema(type: 'integer'), example: 12),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Career role saved (or already saved).',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Career role saved.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'career_role_id', type: 'integer', example: 12),
                                new OA\Property(property: 'is_saved', type: 'boolean', example: true),
                                new OA\Property(property: 'saved_at', type: 'string', format: 'date-time', nullable: true, example: '2026-11-13T14:05:00+00:00'),
                            ],
                            type: 'object',
                        ),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f2504e0-4f89-41d3-9a0c-0305e82c3301'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: '`CAREER_ROLE_NOT_FOUND` — the career role does not exist.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: '`CAREER_ROLE_NOT_APPROVED` — the role exists but is not approved or is inactive.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function savedCareerRolesStore(): void {}

    #[OA\Delete(
        path: '/v1/saved-career-roles/{id}',
        operationId: 'savedCareerRolesDestroy',
        tags: ['Career Roles'],
        summary: 'Remove a career role from the saved list',
        description: 'Removes the saved row for the caller. Idempotent: removing a role that was never saved still '
            .'answers 200 with `is_saved: false`, so the client never has to guess the previous state.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Career role id.', schema: new OA\Schema(type: 'integer'), example: 12),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Career role removed from the saved list.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Career role removed from saved roles.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'career_role_id', type: 'integer', example: 12),
                                new OA\Property(property: 'is_saved', type: 'boolean', example: false),
                            ],
                            type: 'object',
                        ),
                        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', example: '3f2504e0-4f89-41d3-9a0c-0305e82c3301'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: '`CAREER_ROLE_NOT_FOUND` — the career role does not exist.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: '`CAREER_ROLE_NOT_APPROVED` — the role exists but is not approved or is inactive.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function savedCareerRolesDestroy(): void {}
}
