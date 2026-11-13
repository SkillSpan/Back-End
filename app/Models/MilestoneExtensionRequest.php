<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MilestoneExtensionRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'project_milestone_id',
        'requested_by',
        'reason',
        'new_due_date',
        'status',
        'decision_reason',
        'applied_deadline',
        'decided_by',
        'decided_at',
    ];

    protected $casts = [
        'new_due_date' => 'date',
        'applied_deadline' => 'date',
        'decided_at' => 'datetime',
    ];

    public function milestone()
    {
        return $this->belongsTo(ProjectMilestone::class, 'project_milestone_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
