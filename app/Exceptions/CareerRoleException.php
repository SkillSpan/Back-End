<?php

namespace App\Exceptions;

use Exception;

/**
 * Raised by the admin career-role management for a request it must refuse.
 *
 * Same `codeName` / `status` / `details` shape as SpecializationException, so
 * the admin panel's JSON error envelope stays uniform across both screens.
 */
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

    /**
     * Activation is only meaningful for a role whose definition has been
     * approved.
     *
     * `status` and `is_active` are independent: activation says "offer this
     * role to learners", approval says "this role definition is trustworthy".
     * Offering a draft (or retired) definition would put unreviewed required
     * skills in front of students and let them start an assessment against
     * them, so the transition is refused rather than silently ignored. The
     * operator approves the role first, then activates it.
     *
     * 409 because the request is well-formed; it conflicts with the row's
     * current state.
     */
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
