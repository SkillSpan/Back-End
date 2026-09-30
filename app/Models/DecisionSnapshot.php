<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * US-INT-01 — one immutable decision per intelligence calculation.
 * The snapshot column holds the validated pre-FastAPI input state
 * (role, required skills, learner skills, availability, versions).
 */
class DecisionSnapshot extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /**
     * Which flow produced the decision. Two flows persist succeeded
     * snapshots: the intelligence flow (skill gaps + readiness + roadmap)
     * and the legacy composite readiness flow (readiness + skill-match
     * gaps, no roadmap). `GET /api/v1/intelligence/latest` reads the
     * intelligence flow only — without this discriminator a readiness
     * decision silently shadowed the learner's roadmap.
     */
    public const FLOW_INTELLIGENCE = 'intelligence';

    public const FLOW_READINESS_LEGACY = 'readiness_legacy';

    protected $fillable = [
        'decision_uuid',
        'flow',
        'student_profile_id',
        'career_role_id',
        'career_role_version',
        'algorithm_version',
        'configuration_version',
        'request_id',
        'snapshot',
        'status',
        'calculated_at',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'calculated_at' => 'datetime',
    ];

    /**
     * Decisions produced by the intelligence flow. A NULL flow is treated
     * as intelligence: that is what every row written before the column
     * existed was, and the migration backfills them anyway.
     */
    public function scopeIntelligenceFlow(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('flow', self::FLOW_INTELLIGENCE)
                ->orWhereNull('flow');
        });
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function careerRole(): BelongsTo
    {
        return $this->belongsTo(CareerRole::class);
    }

    public function skillGapResults(): HasMany
    {
        return $this->hasMany(SkillGapResult::class);
    }

    public function readinessResults(): HasMany
    {
        return $this->hasMany(ReadinessResult::class);
    }

    public function roadmaps(): HasMany
    {
        return $this->hasMany(Roadmap::class);
    }
}
