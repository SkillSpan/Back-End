<?php

namespace App\Exceptions;

class ReadinessIntegrationException extends ReadinessException
{
    public function __construct(
        string $message,
        int $status = 502,
        string $codeName = 'READINESS_INTEGRATION_ERROR',
        array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $codeName, $details, $previous);
    }
}
