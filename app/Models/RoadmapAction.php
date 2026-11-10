<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoadmapAction extends Model
{
    use HasFactory;

    public const TYPES = [
        'assessment',
        'resource',
        'practice',
        'simulated_project',
        'real_project',
    ];

    public const PHASE_ORDER = [
        'foundations' => 1,
        'core_skills' => 2,
        'applied_practice' => 3,
        'career_readiness' => 4,
    ];

    protected $fillable = ['roadmap_id', 'phase', 'type', 'target_skill_id', 'prerequisite_skill_id', 'title', 'objective', 'description', 'priority_score', 'estimated_hours', 'estimated_duration_weeks', 'order_index', 'fastapi_order', 'completion_criteria', 'explanation', 'blocking_prerequisite_skill_ids'];

    protected $casts = [
        'completed_at' => 'datetime',
        'estimated_hours' => 'float',
        'estimated_duration_weeks' => 'integer',
        'completion_criteria' => 'array',
        'explanation' => 'array',
        'blocking_prerequisite_skill_ids' => 'array',
    ];

    public function roadmap()
    {
        return $this->belongsTo(Roadmap::class);
    }

    public function targetSkill()
    {
        return $this->belongsTo(Skill::class, 'target_skill_id');
    }

    public function prerequisiteSkill()
    {
        return $this->belongsTo(Skill::class, 'prerequisite_skill_id');
    }

    public function prerequisiteEntries()
    {
        return $this->hasMany(RoadmapActionPrerequisite::class);
    }

    public function prerequisites()
    {
        return $this->belongsToMany(
            Skill::class,
            'roadmap_action_prerequisites',
            'roadmap_action_id',
            'skill_id'
        );
    }

    public function learningActivities()
    {
        return $this->hasMany(LearningActivity::class);
    }
}
