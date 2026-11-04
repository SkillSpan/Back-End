<?php

namespace App\Exceptions;

use Exception;

/**
 * Every domain failure in the company's talent engagement surface: shortlists,
 * notes, comparison and contact requests.
 *
 * Same shape as ProjectException / ApplicationException — status + a stable
 * `codeName` + `details` — so the controllers map it straight onto the shared
 * envelope and the frontend branches on `code` rather than parsing prose.
 */
class TalentException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly string $codeName = 'TALENT_ERROR',
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
