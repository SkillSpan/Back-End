<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_CHANGES_REQUESTED = 'changes_requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_OPEN = 'open';

    public const STATUS_SELECTION = 'selection';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_CHANGES_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_OPEN,
        self::STATUS_SELECTION,
        self::STATUS_ACTIVE,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_ARCHIVED,
    ];

    public const EDITABLE_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_CHANGES_REQUESTED,
    ];

    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED],
        self::STATUS_SUBMITTED => [
            self::STATUS_APPROVED,
            self::STATUS_CHANGES_REQUESTED,
            self::STATUS_REJECTED,
        ],
        self::STATUS_CHANGES_REQUESTED => [self::STATUS_SUBMITTED],
        self::STATUS_APPROVED => [self::STATUS_OPEN],
        self::STATUS_OPEN => [self::STATUS_SELECTION, self::STATUS_CANCELLED],
        self::STATUS_SELECTION => [
            self::STATUS_ACTIVE,
            self::STATUS_OPEN,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_ACTIVE => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_UNDER_REVIEW => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [self::STATUS_ARCHIVED],
        self::STATUS_CANCELLED => [self::STATUS_ARCHIVED],
        self::STATUS_ARCHIVED => [],
        self::STATUS_REJECTED => [self::STATUS_DRAFT, self::STATUS_ARCHIVED],
    ];

    public const VERSIONED_FIELDS = [
        'type',
        'domain',
        'difficulty',
        'work_mode',
        'role',
        'career_role_id',
        'schedule',
        'confidentiality',
    ];

    protected $fillable = ['organization_id', 'owner_id', 'type', 'domain', 'title', 'description', 'objectives', 'learning_outcomes', 'difficulty', 'work_mode', 'role', 'career_role_id', 'schedule', 'capacity', 'min_team_size', 'start_date', 'end_date', 'application_deadline', 'status', 'confidentiality', 'rubric_id', 'version'];

    protected $casts = ['learning_outcomes' => 'array', 'application_deadline' => 'date', 'start_date' => 'date', 'end_date' => 'date', 'approved_at' => 'datetime', 'difficulty' => 'float'];

    public function canTransitionTo(string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function rubric()
    {
        return $this->belongsTo(Rubric::class);
    }

    public function careerRole()
    {
        return $this->belongsTo(CareerRole::class);
    }

    public function clonedFrom()
    {
        return $this->belongsTo(Project::class, 'cloned_from_project_id');
    }

    public function requiredSkills()
    {
        return $this->hasMany(ProjectRequiredSkill::class);
    }

    public function eligibilityConstraints()
    {
        return $this->hasMany(ProjectEligibilityConstraint::class);
    }

    public function projectRoles()
    {
        return $this->hasMany(ProjectRole::class);
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    public function teams()
    {
        return $this->hasMany(ProjectTeam::class);
    }

    public function milestones()
    {
        return $this->hasMany(ProjectMilestone::class);
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }

    public function evaluations()
    {
        return $this->hasMany(Evaluation::class);
    }

    public function matchingSnapshots()
    {
        return $this->hasMany(ProjectMatchingSnapshot::class);
    }

    public function acceptedApplications()
    {
        return $this->hasMany(Application::class)
            ->whereIn('status', Application::CAPACITY_STATUSES);
    }
}
