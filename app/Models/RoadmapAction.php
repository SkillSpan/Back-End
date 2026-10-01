<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoadmapAction extends Model
{
    use HasFactory;

    /**
     * The ONLY allowed roadmap action types. Mirrors the `type` enum in
     * the roadmap_actions migration and is the single source consumed by
     * the response validator and the persistence service — an unknown
     * type is a contract violation and must never be coerced.
     */
    public const TYPES = [
        'assessment',
        'resource',
        'practice',
        'simulated_project',
        'real_project',
    ];

    /**
     * Roadmap v1 contract fields:
     *   estimated_hours          — effort required to complete the action;
     *   estimated_duration_weeks — calendar duration in weeks, derived from
     *                              the learner's weekly availability.
     *
     * `estimated_duration_hours` is a LEGACY column that predates the v1
     * contract. Its column and historical data are preserved in the
     * database (no destructive drop), but it is NOT part of the Roadmap v1
     * contract: it is not mass-assignable, not validated, not persisted and
     * not serialized, and it is never converted into weeks.
     */
    protected $fillable = ['roadmap_id', 'phase', 'type', 'target_skill_id', 'prerequisite_skill_id', 'title', 'objective', 'description', 'priority_score', 'estimated_hours', 'estimated_duration_weeks', 'order_index', 'fastapi_order', 'completion_criteria', 'explanation', 'blocking_prerequisite_skill_ids'];

    protected $casts = [
        'completed_at' => 'datetime',
        'estimated_hours' => 'float',
        'estimated_duration_weeks' => 'integer',
        // Roadmap v1: these two are LISTS of strings, and
        // blocking_prerequisite_skill_ids is a list of integer skill ids
        // (distinct from prerequisite_skill_ids). Stored as JSON text and
        // returned as arrays.
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

    /**
     * Legacy single-prerequisite relation. Kept for backward compatibility;
     * new code reads the full set through prerequisites().
     */
    public function prerequisiteSkill()
    {
        return $this->belongsTo(Skill::class, 'prerequisite_skill_id');
    }

    /**
     * The full prerequisite set, via roadmap_action_prerequisites.
     * Source of truth for multiple prerequisites per action.
     */
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
