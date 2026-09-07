<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearnerSkill extends Model
{
    protected $fillable = [
        'learner_id',
        'skill_id',
        'latest_skill_evaluation_id',
        'level',
        'confidence_score',
        'source_type',
        'algorithm_version',
        'configuration_version',
        'source_contributions',
        'calculated_at',
    ];

    protected $casts = [
        'source_contributions' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function learner()
    {
        return $this->belongsTo(User::class, 'learner_id');
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }

    public function latestEvaluation()
    {
        return $this->belongsTo(SkillEvaluation::class, 'latest_skill_evaluation_id');
    }
}
