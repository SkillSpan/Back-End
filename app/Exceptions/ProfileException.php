<?php

namespace App\Exceptions;

use Exception;

/**
 * US-COMP-04 — a profile or document request that is well-formed but not
 * allowed: an unsupported document type, a file the screening rejected, a
 * field the company is not allowed to edit.
 *
 * Shaped exactly like ReadinessException so the controllers that already
 * catch that one read the same way: `codeName` goes into the response `code`,
 * `status` into the HTTP status. The story asks for "clear validation and
 * authorization errors" (BR / API task), and a client that branches on a code
 * cannot do it against a prose message.
 */
class ProfileException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly string $codeName = 'PROFILE_ERROR',
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
