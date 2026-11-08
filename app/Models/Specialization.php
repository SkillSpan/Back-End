<?php

namespace App\Models;

use App\Services\CareerRoles\CareerRoleCatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An academic specialization (Computer Science, Data Science, …) or the
 * special "Self-Learning / Free Track".
 *
 * WHAT A SPECIALIZATION DECIDES — AND WHAT IT DOES NOT
 * ----------------------------------------------------
 * It used to decide WHICH career roles a learner could pick: a role was only
 * available when a `career_role_specialization` pivot row linked it. Since the
 * MVP it decides only the ORDER of the list — every approved + active role is
 * offered under every active specialization, and the specialization's own
 * roles are simply shown first (see config/mvp.php).
 *
 * The pivot is still maintained because it is the curation view the admin
 * panel edits and the mapping the question bank is browsed through. It is no
 * longer an access-control list, so removing a pivot row never removes a role
 * from a learner's options.
 */
class Specialization extends Model
{
    protected $fillable = ['name', 'description', 'is_active', 'is_free_track'];

    protected $casts = ['is_active' => 'boolean', 'is_free_track' => 'boolean'];

    /**
     * Career roles linked to this specialization in the curation pivot.
     *
     * This is the ADMIN mapping ("which roles does this track cover?"), not
     * the learner's option list — use {@see selectableCareerRolesQuery()} for
     * anything a learner sees.
     */
    public function careerRoles()
    {
        return $this->belongsToMany(CareerRole::class, 'career_role_specialization')->withTimestamps();
    }

    /**
     * Only specializations that may be offered to a learner.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('specializations.is_active', true);
    }

    /**
     * The special "Self-Learning / Free Track" specialization.
     *
     * Its available career roles are every role in the system, so a learner
     * who studied outside a formal academic track is not restricted to one
     * specialization's role list.
     */
    public function isFreeTrack(): bool
    {
        return (bool) $this->is_free_track;
    }

    /**
     * The career roles offered under this specialization.
     *
     * Under the MVP policy this is the same set for every active
     * specialization — every approved + active role — and the specialization
     * only changes the ORDER (its priority roles first). The free track has no
     * priority list, so it gets the same set in a neutral order.
     *
     * The result is already ordered, so callers must not add another
     * `orderBy` before paginating unless they intend to override the priority.
     */
    public function selectableCareerRolesQuery(): Builder
    {
        return app(CareerRoleCatalogService::class)->selectableQuery($this);
    }

    /**
     * Whether the given career role may be chosen under this specialization.
     *
     * The specialization no longer restricts the choice: any role that is
     * approved and active is valid, whatever the learner's track. An inactive
     * or unapproved role is never valid — that is the only remaining rule.
     */
    public function allowsCareerRole(int $careerRoleId): bool
    {
        return CareerRole::query()
            ->selectable()
            ->whereKey($careerRoleId)
            ->exists();
    }
}
