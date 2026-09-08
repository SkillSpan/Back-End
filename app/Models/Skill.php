<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'category', 'description', 'version', 'parent_skill_id'];

    public function aliases()
    {
        return $this->hasMany(SkillAlias::class);
    }

    public function parent()
    {
        return $this->belongsTo(Skill::class, 'parent_skill_id');
    }

    public function children()
    {
        return $this->hasMany(Skill::class, 'parent_skill_id');
    }

    public function evidences()
    {
        return $this->hasMany(SkillEvidence::class);
    }

    public function evaluations()
    {
        return $this->hasMany(SkillEvaluation::class);
    }

    public function careerRoles()
    {
        return $this->belongsToMany(CareerRole::class, 'career_role_skills')->withPivot('required_level', 'importance_weight', 'is_critical')->withTimestamps();
    }
}
