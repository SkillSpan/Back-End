<?php

namespace App\Exceptions;

use Exception;

class CareerRoleException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly string $codeName = 'CAREER_ROLE_ERROR',
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function cannotActivate(int $careerRoleId, string $status): self
    {
        return new self(
            'A career role must be approved before it can be activated.',
            409,
            'CAREER_ROLE_ACTIVATION_REFUSED',
            ['career_role_id' => $careerRoleId, 'status' => $status],
        );
    }
}
