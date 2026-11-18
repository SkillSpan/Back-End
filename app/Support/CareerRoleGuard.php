<?php

namespace App\Support;

use App\Models\CareerRole;

/**
 * Single place where the MVP rule "inactive career roles cannot be used for
 * progression" is applied, mirroring the rule already enforced when a learner
 * picks a career goal (see UpdateProfileRequest): a learner may keep a role
 * that was deactivated underneath them, but may not newly select or progress
 * an out-of-scope role.
 */
final class CareerRoleGuard
{
    /**
     * @param  int|null  $currentRoleId  the learner's persisted primary career role
     */
    public static function allowsProgression(CareerRole $careerRole, ?int $currentRoleId): bool
    {
        if ($careerRole->isSelectable()) {
            return true;
        }

        // Continuity: the role is already the learner's own goal, so existing
        // dashboards and history keep working after a catalogue change.
        return $currentRoleId !== null && (int) $currentRoleId === (int) $careerRole->id;
    }
}
