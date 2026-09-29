<?php

namespace App\Exceptions;

use Exception;

/**
 * US-MATCH-02 — every domain failure in the project-application workflow.
 *
 * Follows the same shape as ReadinessException (status + stable codeName +
 * details) so ApplicationController maps it straight onto the shared error
 * envelope used by every other endpoint in this API.
 *
 * Codes are deliberately specific (PROJECT_NOT_AVAILABLE, APPLICATION_DUPLICATE,
 * PROJECT_FULL, …) so the frontend can branch on `code` instead of parsing
 * `message`, which is user-facing prose and may change.
 */
class ApplicationException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly string $codeName = 'APPLICATION_ERROR',
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
