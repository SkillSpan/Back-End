<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CareerRoleSkill extends Model
{
    use HasFactory;

    protected $fillable = ['career_role_id', 'skill_id', 'required_level', 'importance_weight', 'is_critical', 'prerequisite_skill_id'];

    protected $casts = ['is_critical' => 'boolean'];

    public function careerRole()
    {
        return $this->belongsTo(CareerRole::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }

    public function prerequisiteSkill()
    {
        return $this->belongsTo(Skill::class, 'prerequisite_skill_id');
    }

    /**
     * Multiple prerequisites per required skill, via career_role_skill_dependencies.
     * Supersedes the single nullable prerequisite_skill_id column above, which is
     * kept only for backward compatibility and is not populated by new code.
     */
    public function dependencies()
    {
        return $this->hasMany(CareerRoleSkillDependency::class);
    }

    public function prerequisites()
    {
        return $this->belongsToMany(
            Skill::class,
            'career_role_skill_dependencies',
            'career_role_skill_id',
            'prerequisite_skill_id'
        );
    }
}
