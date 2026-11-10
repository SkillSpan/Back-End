<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
