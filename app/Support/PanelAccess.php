<?php

namespace App\Support;

use App\Models\ProfessionalProfile;
use App\Models\User;

/**
 * Who may open the operations panel?
 *
 * One predicate, used everywhere, because the panel has two audiences and the
 * definition of the second one is not obvious:
 *
 *   - an **administrator** holds the `admin` role in the `user_role` pivot;
 *   - a **mentor** has no role slug at all. Mentor identity *is* a
 *     `ProfessionalProfile` row with `type = 'mentor'` — see
 *     EnsureUserIsMentor. Looking for a 'mentor' role would find nothing and
 *     silently lock every mentor out.
 *
 * WHY IT IS A CLASS AND NOT A MIDDLEWARE
 * --------------------------------------
 * The same question is asked in three different shapes: a Blade route needs a
 * boolean to `abort_unless`, a JSON endpoint needs a boolean to build a 403
 * body, and the inbox scope needs it to decide between "everything" and "only
 * mine". A middleware can only answer the first. Keeping the predicate here
 * means those three can never drift apart — which matters, because a route
 * that admits a mentor into a page the API then refuses is a broken feature
 * rather than a strict one.
 *
 * NOTE ON THE VERIFIED GATE
 * -------------------------
 * Any `type = 'mentor'` profile counts, verified or not. The `verified`
 * requirement belongs on the routes that grant mentor *powers* over new
 * learners; a mentor who is mid-verification still has to be able to answer a
 * learner they were already assigned, and locking them out of the reply box
 * would strand that learner.
 */
final class PanelAccess
{
    /** Administrators and mentors, and nobody else. */
    public static function allows(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return self::isAdministrator($user) || self::isMentor($user);
    }

    /**
     * Is this account an administrator?
     *
     * The `admin` role is the only thing consulted — never `is_admin`, which
     * does not exist on this schema.
     */
    public static function isAdministrator(?User $user): bool
    {
        return $user instanceof User && $user->hasRole('admin');
    }

    /**
     * Is this account a mentor?
     *
     * Existence check rather than a loaded relation so it stays correct when
     * the caller has not eager-loaded anything.
     */
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

    /**
     * The role a person would name if asked — for display, never for access.
     *
     * Lives here rather than in the controller because the profile screen and
     * the sidebar both render it, and two copies of this `if` would eventually
     * disagree about what an account is called.
     *
     * Administrator wins when an account somehow holds both capabilities: it
     * is the higher one, and labelling somebody who can read every support
     * thread as a "Mentor" would understate their access.
     */
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
