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
}
