<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EvaluationModerationCase extends Model
{
    use HasFactory;

    public const TYPE_FLAGGED = 'flagged_evaluation';

    public const TYPE_CONFLICT = 'conflict_of_interest';

    public const TYPE_CORRECTION = 'correction_request';

    public const TYPE_DISPUTE = 'dispute';

    public const TYPES = [
        self::TYPE_FLAGGED,
        self::TYPE_CONFLICT,
        self::TYPE_CORRECTION,
        self::TYPE_DISPUTE,
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const DECISION_PRESERVE = 'preserve';

    public const DECISION_REPLACE = 'replace';

    public const DECISION_VOID = 'void';

    public const DECISIONS = [
        self::DECISION_PRESERVE,
        self::DECISION_REPLACE,
        self::DECISION_VOID,
    ];

    protected $fillable = [
        'type',
        'evaluation_id',
        'dispute_id',
        'correction_request_id',
        'source_type',
        'source_id',
        'status',
        'decision',
        'reason',
        'decided_by',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function evaluation()
    {
        return $this->belongsTo(Evaluation::class);
    }

    public function dispute()
    {
        return $this->belongsTo(EvaluationDispute::class);
    }

    public function correctionRequest()
    {
        return $this->belongsTo(EvaluationCorrectionRequest::class);
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
