<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * The project-wide response envelope: `{ "success": ..., "message": ..., "data": ... }`.
 * Only the pieces that are actually returned by the code are documented here.
 */
#[OA\Schema(
    schema: 'ErrorResponse',
    title: 'ErrorResponse',
    description: 'Failure envelope used by most endpoints: `success: false` plus a human-readable message. '
        .'Some endpoints additionally return a machine-readable `code` and a `request_id`.',
    type: 'object',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'message', type: 'string', example: 'No profile exists for this account yet.'),
        new OA\Property(property: 'code', type: 'string', nullable: true, example: 'CAREER_ROLE_NOT_FOUND'),
        new OA\Property(property: 'request_id', type: 'string', nullable: true, format: 'uuid'),
        new OA\Property(property: 'details', type: 'object', nullable: true, additionalProperties: true),
    ],
)]
#[OA\Schema(
    schema: 'ValidationErrorResponse',
    title: 'ValidationErrorResponse',
    description: 'Laravel validation failure (HTTP 422). `errors` maps each field to a list of messages.',
    type: 'object',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'The provided credentials are incorrect.'),
        new OA\Property(
            property: 'errors',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(
                type: 'array',
                items: new OA\Items(type: 'string'),
            ),
            example: ['email' => ['The provided credentials are incorrect.']],
        ),
    ],
)]
class CommonSchemas {}
