<?php

namespace App\Models;

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

    protected $fillable = [
        'decision_uuid',
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
