<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One prerequisite skill of a single roadmap action.
 *
 * Multiple prerequisites per action are stored relationally (this table)
 * rather than in the legacy single `roadmap_actions.prerequisite_skill_id`
 * column, which is kept only for backward compatibility.
 */
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
