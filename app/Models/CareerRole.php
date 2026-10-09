<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CareerRole extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'slug', 'version', 'status', 'effective_date', 'description'];

    protected $casts = ['effective_date' => 'date'];

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
     */
    public function specializations()
    {
        return $this->belongsToMany(Specialization::class, 'career_role_specialization')->withTimestamps();
    }
}
