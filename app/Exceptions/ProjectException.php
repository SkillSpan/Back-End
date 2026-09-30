<?php

namespace App\Exceptions;

use Exception;

/**
 * Project Management Workflow — every domain failure in the project
 * lifecycle: create, update, submit, approve, request changes, reject, open.
 *
 * Same shape as ApplicationException (status + stable codeName + details) so
 * the controller maps it straight onto the shared error envelope, and the
 * frontend can branch on `code` rather than parsing `message`, which is
 * user-facing prose and may change.
 */
class ProjectException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly string $codeName = 'PROJECT_ERROR',
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
