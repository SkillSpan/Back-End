<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Specialization extends Model
{
    protected $fillable = ['name', 'description', 'is_active', 'is_free_track'];

    protected $casts = ['is_active' => 'boolean', 'is_free_track' => 'boolean'];

    /**
     * Career roles relevant to this specialization. Many-to-many: a role
     * such as "Backend Developer" belongs to several specializations.
     */
    public function careerRoles()
    {
        return $this->belongsToMany(CareerRole::class, 'career_role_specialization')->withTimestamps();
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
     * The career roles a learner may pick under this specialization, as a
     * CareerRole query builder the caller can further constrain.
     *
     * A normal specialization is limited to the `career_role_specialization`
     * pivot; the free track is deliberately UNCONSTRAINED — this is the
     * special case the spec asks for, instead of inserting a pivot row for
     * every (free track, career role) pair.
     */
    public function availableCareerRolesQuery(): Builder
    {
        if ($this->isFreeTrack()) {
            return CareerRole::query();
        }

        return CareerRole::query()->whereHas(
            'specializations',
            fn (Builder $query) => $query->whereKey($this->id),
        );
    }

    /**
     * Whether the given career role may be chosen under this
     * specialization. Any existing role is valid for the free track.
     */
    public function allowsCareerRole(int $careerRoleId): bool
    {
        if ($this->isFreeTrack()) {
            return CareerRole::whereKey($careerRoleId)->exists();
        }

        return $this->careerRoles()->whereKey($careerRoleId)->exists();
    }
}
