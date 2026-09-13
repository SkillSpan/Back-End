<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * US-INT-01 — validated FastAPI skill-gap output for one (decision, skill).
 * Append-only: recalculations create new decisions and new rows.
 */
class SkillGapResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'decision_snapshot_id',
        'skill_id',
        'current_level',
        'required_level',
        'gap',
        'match_score',
        'importance_weight',
        'is_critical',
        'confidence',
        'status',
        'explanation',
    ];

    protected $casts = [
        'is_critical' => 'boolean',
    ];

    public function decisionSnapshot(): BelongsTo
    {
        return $this->belongsTo(DecisionSnapshot::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
