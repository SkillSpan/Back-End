<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CareerRoleSkillDependency extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'career_role_skill_id',
        'prerequisite_skill_id',
    ];

    public function careerRoleSkill()
    {
        return $this->belongsTo(CareerRoleSkill::class);
    }

    public function prerequisiteSkill()
    {
        return $this->belongsTo(Skill::class, 'prerequisite_skill_id');
    }
}
