<?php

namespace App\Exceptions;

use Exception;

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
