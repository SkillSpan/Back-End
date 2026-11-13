<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EvaluationDispute extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_REJECTED = 'rejected';

    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_UNDER_REVIEW,
    ];

    public const CLASSIFICATIONS = [
        'data_error',
        'configuration_error',
        'software_defect',
        'misunderstanding',
        'abuse',
        'no_issue',
    ];

    public const CORRECTIVE_ACTIONS = [
        'repair_data',
        'supersede_decision',
        'rollback_configuration',
        'update_explanation',
        'reject_with_reason',
    ];

    protected $fillable = [
        'evaluation_id',
        'submitted_by',
        'grounds',
        'disputed_criteria',
        'rubric_version',
        'evidence',
        'status',
        'classification',
        'corrective_action',
        'outcome_reason',
        'exception_id',
        'resolution_notes',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'disputed_criteria' => 'array',
        'rubric_version' => 'integer',
    ];

    public function evaluation()
    {
        return $this->belongsTo(Evaluation::class);
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
