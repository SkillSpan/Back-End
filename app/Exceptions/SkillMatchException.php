<?php

namespace App\Exceptions;

use Exception;

/**
 * Semantic errors for POST /api/v1/skill-match, per the Data Science
 * contract. Rendered as HTTP 422 with body:
 *   {"detail": {"code": "...", "message": "..."}}
 * — distinct from the rest of the app's {code, message, request_id} shape
 * because this specific endpoint's error contract was specified by Data
 * Science to mirror their own FastAPI/Pydantic error format.
 */
class SkillMatchException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $codeName,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
