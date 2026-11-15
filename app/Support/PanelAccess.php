<?php

namespace App\Support;

use App\Models\ProfessionalProfile;
use App\Models\User;

final class PanelAccess
{
    public static function allows(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return self::isAdministrator($user) || self::isMentor($user);
    }

    public static function isAdministrator(?User $user): bool
    {
        return $user instanceof User && $user->hasRole('admin');
    }

    public static function isMentor(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return ProfessionalProfile::query()
            ->where('user_id', $user->id)
            ->where('type', 'mentor')
            ->exists();
    }

    public static function roleLabel(?User $user): string
    {
        if (self::isAdministrator($user)) {
            return 'Administrator';
        }

        if (self::isMentor($user)) {
            return 'Mentor';
        }

        return 'Member';
    }
}
