<?php

namespace App\Exceptions;

use Exception;

/**
 * Raised by the admin specialization management for a request it must
 * refuse. Same `codeName` / `status` / `details` shape as the other admin
 * domain exceptions, so the panel's JSON error envelope stays uniform.
 */
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

    /**
     * A specialization still has career roles linked to it.
     *
     * Deleting it would cascade those pivot rows away and silently change
     * which roles learners can reach from it. Refused; the operator can
     * unlink the roles first, or deactivate the specialization instead.
     */
    public static function inUse(int $specializationId, int $linkedRoleCount): self
    {
        return new self(
            'This specialization cannot be deleted because it is being used.',
            409,
            'SPECIALIZATION_IN_USE',
            ['specialization_id' => $specializationId, 'linked_career_roles' => $linkedRoleCount],
        );
    }

    /**
     * The Self-Learning / Free Track specialization is a system row: its
     * behaviour is what lets self-taught learners pick any career role, so
     * it may be renamed or deactivated but not deleted.
     */
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
