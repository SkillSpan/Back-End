<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EvaluationException extends Model
{
    use HasFactory;

    public const TYPE_LATE_DISPUTE = 'late_dispute';

    public const TYPE_DUPLICATE_DISPUTE = 'duplicate_dispute';

    public const TYPE_MISSING_DELIVERABLE = 'missing_deliverable';

    public const TYPES = [
        self::TYPE_LATE_DISPUTE,
        self::TYPE_DUPLICATE_DISPUTE,
        self::TYPE_MISSING_DELIVERABLE,
    ];

    protected $fillable = ['type', 'subject_type', 'subject_id', 'actor_id', 'reason'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
