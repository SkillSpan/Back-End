<?php

namespace App\Exceptions;

/**
 * US-INT-01 intelligence integration errors. Stable code names are part
 * of the public contract; never expose internal traces or secrets.
 */
class IntelligenceException extends ReadinessException
{
    public function __construct(
        string $message,
        int $status = 502,
        string $codeName = 'INTELLIGENCE_INVALID_RESPONSE',
        array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $codeName, $details, $previous);
    }
}
