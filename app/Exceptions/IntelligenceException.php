<?php

namespace App\Exceptions;

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
