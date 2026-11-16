<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubmissionFirstPassCheck extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ERROR = 'error';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RUNNING,
        self::STATUS_PASSED,
        self::STATUS_FAILED,
        self::STATUS_ERROR,
    ];

    protected $fillable = ['submission_id', 'status', 'items', 'notes', 'started_at', 'completed_at'];

    protected $casts = [
        'items' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function submission()
    {
        return $this->belongsTo(Submission::class);
    }

    public function passed(): bool
    {
        return $this->status === self::STATUS_PASSED;
    }
}
