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
}
