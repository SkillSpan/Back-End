<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoadmapAction extends Model
{
    use HasFactory;

    protected $fillable = ['roadmap_id', 'phase', 'type', 'target_skill_id', 'prerequisite_skill_id', 'title', 'objective', 'description', 'priority_score', 'estimated_hours', 'estimated_duration_hours', 'order_index', 'fastapi_order', 'completion_criteria', 'explanation'];

    protected $casts = ['completed_at' => 'datetime'];

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

    public function learningActivities()
    {
        return $this->hasMany(LearningActivity::class);
    }
}
