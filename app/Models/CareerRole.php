<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A career role (Backend Developer, Data Scientist, …).
 *
 * TWO INDEPENDENT STATES
 * ----------------------
 * `status` is the content-governance state of the definition: draft,
 * approved or retired. It answers "may this role be trusted as a source of
 * required skills?".
 *
 * `is_active` is the product state: "is this role offered to learners right
 * now?". It is what the MVP uses to keep out-of-scope roles out of every
 * picker without retiring or deleting them.
 *
 * A role may be picked / listed only when BOTH hold — see
 * {@see CareerRole::scopeSelectable()}. Everything that reads a role the
 * learner already chose (assessment history, readiness, project snapshots)
 * must keep working for an inactive role, so those code paths deliberately
 * do NOT use the `selectable` scope.
 *
 * `is_active` is hidden from serialisation on purpose: the existing
 * career-role JSON payload is part of the frontend contract, and an inactive
 * role is simply never listed. Keeping the column out of the payload leaves
 * that contract byte-for-byte unchanged.
 */
class CareerRole extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'slug', 'version', 'status', 'effective_date', 'description', 'is_active'];

    protected $casts = ['effective_date' => 'date', 'is_active' => 'boolean'];

    /**
     * Internal activation flag — not part of the public career-role payload.
     *
     * @var list<string>
     */
    protected $hidden = ['is_active'];

    public function skills()
    {
        return $this->belongsToMany(Skill::class, 'career_role_skills')->withPivot('required_level', 'importance_weight', 'is_critical')->withTimestamps();
    }

    public function roleSkills()
    {
        return $this->hasMany(CareerRoleSkill::class);
    }

    /**
     * US-MATCH-DATA-03 — projects targeting this career role.
     *
     * Inverse of Project::careerRole(). A career role is the single source of
     * truth for which skills a project may require.
     */
    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function marketFactors()
    {
        return $this->hasMany(MarketFactor::class);
    }

    public function readinessResults()
    {
        return $this->hasMany(ReadinessResult::class);
    }

    public function roadmaps()
    {
        return $this->hasMany(Roadmap::class);
    }

    /**
     * Specializations this career role belongs to. Many-to-many — see
     * Specialization::careerRoles() for the inverse.
     *
     * NOTE: this pivot is a curation/mapping view for the admin panel. Since
     * the MVP it no longer restricts which roles a learner may choose — see
     * Specialization::selectableCareerRolesQuery().
     */
    public function specializations()
    {
        return $this->belongsToMany(Specialization::class, 'career_role_specialization')->withTimestamps();
    }

    /**
     * Roles that may be offered to a learner: approved AND active.
     *
     * This is the only filter a picker, a list endpoint or a "start a new
     * assessment" path should use. Reads of already-recorded choices must
     * stay on the plain query so a deactivation never locks a learner out of
     * their own history.
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('career_roles.status', 'approved')
            ->where('career_roles.is_active', true);
    }

    /**
     * Whether this role may be offered to a learner.
     */
    public function isSelectable(): bool
    {
        return $this->status === 'approved' && (bool) $this->is_active;
    }
}
