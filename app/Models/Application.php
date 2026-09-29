<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * US-MATCH-02 — a learner's application to a project.
 *
 * Statuses reuse the existing dictionary from
 * 2026_01_01_003000_create_applications_table; no parallel status system.
 *
 * The `active_key` column is DERIVED here, never supplied by callers. It exists
 * purely so the database can enforce "at most one active application per
 * learner per project" via unique(project_id, applicant_id, active_key):
 * active rows carry 1, terminal rows carry NULL, and NULLs do not collide in a
 * unique index on MySQL or SQLite.
 */
class Application extends Model
{
    use HasFactory;

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_SHORTLISTED = 'shortlisted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WAITLISTED = 'waitlisted';

    public const STATUS_WITHDRAWN = 'withdrawn';

    /**
     * Statuses that count as an "active application": they block a second
     * application to the same project and keep active_key = 1.
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_SHORTLISTED,
        self::STATUS_ACCEPTED,
        self::STATUS_WAITLISTED,
    ];

    /**
     * Statuses that occupy a project capacity slot. Only `accepted` consumes
     * capacity — submitted/shortlisted/waitlisted applications do not, which is
     * what makes "capacity reached" mean "all slots awarded".
     */
    public const CAPACITY_STATUSES = [
        self::STATUS_ACCEPTED,
    ];

    /**
     * Allowed status transitions. Withdrawal is terminal; nothing is allowed to
     * move out of it, and a learner may only ever move their own application to
     * `withdrawn` (enforced in the service, not here).
     *
     * @var array<string, array<int, string>>
     */
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
        // Derived, never trusted from input.
        static::saving(function (self $application): void {
            $application->active_key = $application->isActive() ? 1 : null;
        });

        // Submitted timestamp is set once, on insert.
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

    /**
     * Whether this application may move to $to, per the existing workflow.
     */
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

    /**
     * The recommendation this application originated from, when applicable.
     */
    public function recommendation()
    {
        return $this->belongsTo(Recommendation::class);
    }
}
