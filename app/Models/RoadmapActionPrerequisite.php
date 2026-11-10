<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoadmapActionPrerequisite extends Model
{
    protected $fillable = [
        'roadmap_action_id',
        'skill_id',
    ];

    public function roadmapAction()
    {
        return $this->belongsTo(RoadmapAction::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }
}
