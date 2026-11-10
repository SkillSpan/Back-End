<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    use HasFactory;

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_SHORTLISTED = 'shortlisted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WAITLISTED = 'waitlisted';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const ACTIVE_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_SHORTLISTED,
        self::STATUS_ACCEPTED,
        self::STATUS_WAITLISTED,
    ];

    public const CAPACITY_STATUSES = [
        self::STATUS_ACCEPTED,
    ];

    public const TRANSITIONS = [
        self::STATUS_SUBMITTED => [
            self::STATUS_SHORTLISTED,
            self::STATUS_ACCEPTED,
            self::STATUS_REJECTED,
            self::STATUS_WAITLISTED,
            self::STATUS_WITHDRAWN,
        ],
        self::STATUS_SHORTLISTED => [
            self::STATUS_ACCEPTED,
            self::STATUS_REJECTED,
            self::STATUS_WAITLISTED,
            self::STATUS_WITHDRAWN,
        ],
        self::STATUS_WAITLISTED => [
            self::STATUS_ACCEPTED,
            self::STATUS_REJECTED,
            self::STATUS_WITHDRAWN,
        ],
        self::STATUS_ACCEPTED => [self::STATUS_WITHDRAWN],
        self::STATUS_REJECTED => [],
        self::STATUS_WITHDRAWN => [],
    ];

    protected $fillable = [
        'project_id',
        'applicant_id',
        'project_role_id',
        'status',
        'project_version',
        'application_data',
        'recommendation_id',
        'recommendation_algorithm_version',
        'recommendation_configuration_version',
        'idempotency_key',
        'request_fingerprint',
        'submitted_at',
        'withdrawn_at',
        'decided_by',
        'decided_at',
        'decision_reason',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'submitted_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'application_data' => 'array',
        'project_version' => 'integer',
        'active_key' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $application): void {
            $application->active_key = $application->isActive() ? 1 : null;
        });

        static::creating(function (self $application): void {
            if ($application->submitted_at === null) {
                $application->submitted_at = now();
            }
        });
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isWithdrawn(): bool
    {
        return $this->status === self::STATUS_WITHDRAWN;
    }

    public function canTransitionTo(string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function applicant()
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function projectRole()
    {
        return $this->belongsTo(ProjectRole::class);
    }

    public function recommendation()
    {
        return $this->belongsTo(Recommendation::class);
    }
}
