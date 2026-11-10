<?php

namespace App\Exceptions;

use Exception;

class SpecializationException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly string $codeName = 'SPECIALIZATION_ERROR',
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function inUse(int $specializationId, int $linkedRoleCount): self
    {
        return new self(
            'This specialization cannot be deleted because it is being used.',
            409,
            'SPECIALIZATION_IN_USE',
            ['specialization_id' => $specializationId, 'linked_career_roles' => $linkedRoleCount],
        );
    }

    public static function freeTrackProtected(int $specializationId): self
    {
        return new self(
            'The Self-Learning / Free Track specialization cannot be deleted.',
            409,
            'SPECIALIZATION_FREE_TRACK_PROTECTED',
            ['specialization_id' => $specializationId],
        );
    }

    public static function careerRoleTitleTaken(string $title): self
    {
        return new self(
            'A career role with this title already exists.',
            422,
            'CAREER_ROLE_TITLE_TAKEN',
            ['title' => $title],
        );
    }
}
