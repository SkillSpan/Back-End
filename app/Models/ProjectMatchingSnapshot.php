<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Project matching decision snapshot.
 *
 * One immutable row per validated project-matching input set, captured
 * BEFORE the matching/payload layer (Task 9). Captures the validated
 * state of availability, authorization, eligibility, learner context,
 * project context, and the resolved algorithm/configuration versions.
 *
 * This is intentionally separate from the career-role DecisionSnapshot
 * (which has a required career_role_id FK). Project matching operates on
 * a different domain entity (Project) and does not always have a career
 * role context.
 */
class ProjectMatchingSnapshot extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_FAILED = 'failed';

    protected $table = 'project_matching_snapshots';

    protected $fillable = [
        'project_id',
        'student_profile_id',
        'project_version',
        'algorithm_version',
        'configuration_version',
        'request_id',
        'snapshot',
        'status',
        'validated_at',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'validated_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }
}
