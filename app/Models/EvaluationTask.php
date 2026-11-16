<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EvaluationTask extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_FINALIZED = 'finalized';

    public const STATUS_UNROUTABLE = 'unroutable';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ASSIGNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_SUBMITTED,
        self::STATUS_FINALIZED,
        self::STATUS_UNROUTABLE,
    ];

    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ASSIGNED,
        self::STATUS_IN_PROGRESS,
    ];

    protected $fillable = [
        'project_id',
        'participant_id',
        'submission_id',
        'evaluator_id',
        'evaluator_type',
        'rubric_id',
        'status',
        'routing_reason',
        'assigned_at',
        'due_at',
        'created_by',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'due_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function participant()
    {
        return $this->belongsTo(User::class, 'participant_id');
    }

    public function submission()
    {
        return $this->belongsTo(Submission::class);
    }

    public function evaluator()
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function rubric()
    {
        return $this->belongsTo(Rubric::class);
    }
}
