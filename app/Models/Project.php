<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    // ─────────────────────────────────────────────────────────────────────
    // Lifecycle — the official Pilot lifecycle. This is the single source of
    // truth: the migration, the service, the validation and the tests all
    // read these constants, so a status can never mean one thing in one place
    // and something else in another.
    // ─────────────────────────────────────────────────────────────────────

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

    /**
     * Legacy value carried over from the original projects enum.
     *
     * The official lifecycle does not contain it and NOTHING in the codebase
     * reads or writes it — it is preserved here, and in the database enum,
     * purely so the migration does not destroy a value the team has not yet
     * decided on. See the Pilot report: whether `closed` becomes an official
     * status, is folded into `cancelled`/`archived`, or is dropped is a
     * product decision that has NOT been taken, so this code does not take it.
     */
    public const STATUS_CLOSED = 'closed';

    /** Every status the workflow recognises, in lifecycle order. */
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

    /**
     * The revisable window: the only statuses in which the owner may edit the
     * project. `submitted` is under review and `open` and beyond is live, so
     * editing either would change what a reviewer approved or what applicants
     * applied to.
     */
    public const EDITABLE_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_CHANGES_REQUESTED,
    ];

    /**
     * Allowed transitions, mirroring Application::TRANSITIONS.
     *
     * `rejected` has no outgoing transition: the Pilot has no agreed
     * resubmission path after a rejection (the fix-and-resubmit path is
     * `changes_requested`), and inventing one would be a product decision this
     * code is not allowed to make.
     *
     * `open → selection` is declared so the lifecycle is complete for the
     * Pilot's shortlist/accept phase. It is deliberately NOT exposed as an
     * endpoint: the required endpoint list stops at `open`, and shortlisting
     * happens through the existing application decisions.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED],
        self::STATUS_SUBMITTED => [
            self::STATUS_APPROVED,
            self::STATUS_CHANGES_REQUESTED,
            self::STATUS_REJECTED,
        ],
        self::STATUS_CHANGES_REQUESTED => [self::STATUS_SUBMITTED],
        self::STATUS_APPROVED => [self::STATUS_OPEN],
        self::STATUS_OPEN => [self::STATUS_SELECTION],
        self::STATUS_SELECTION => [],
        self::STATUS_ACTIVE => [],
        self::STATUS_UNDER_REVIEW => [],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_ARCHIVED => [],
        self::STATUS_REJECTED => [],
    ];

    /**
     * Fields that feed the FastAPI project snapshot (see
     * ProjectMatchingPayloadBuilder). Changing any of them invalidates the
     * recommendations and applications pinned to the previous
     * `project_version`, so the existing `version` column is bumped when one of
     * them actually changes.
     *
     * Purely descriptive fields (title, description, objectives,
     * learning_outcomes) are not here: they do not appear in the matching
     * contract, so editing them must not churn the version.
     *
     * @var array<int, string>
     */
    public const VERSIONED_FIELDS = [
        'type',
        'domain',
        'difficulty',
        'work_mode',
        'role',
        'schedule',
        'confidentiality',
    ];

    protected $fillable = ['organization_id', 'owner_id', 'type', 'domain', 'title', 'description', 'objectives', 'learning_outcomes', 'difficulty', 'work_mode', 'role', 'schedule', 'capacity', 'min_team_size', 'start_date', 'end_date', 'application_deadline', 'status', 'confidentiality', 'rubric_id', 'version'];

    protected $casts = ['learning_outcomes' => 'array', 'application_deadline' => 'date', 'start_date' => 'date', 'end_date' => 'date', 'approved_at' => 'datetime', 'difficulty' => 'float'];

    /**
     * Whether this project may move to $to, per the official lifecycle.
     */
    public function canTransitionTo(string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * Whether the owner may edit the project in its current status.
     */
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

    /**
     * US-MATCH-02 — the roles a learner can select when applying.
     *
     * NOTE: project targeting is NOT modelled here. Relevance is decided by
     * career role + required skills + learner skill levels + difficulty, which
     * already flow through ProjectMatchingService / the FastAPI
     * `target_career_role` payload. Academic specialization is deliberately not
     * a project column or an application gate.
     */
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

    /**
     * Applications that currently occupy a capacity slot.
     *
     * WHICH statuses count is a capacity POLICY, not a schema fact. This
     * relation only exposes the accepted ones so the eventual policy can be
     * swapped without touching the schema. See the capacity policy question in
     * US-MATCH-02_APPLICATION_WORKFLOW_REPORT.md — the rule is not yet
     * confirmed.
     */
    public function acceptedApplications()
    {
        return $this->hasMany(Application::class)
            ->whereIn('status', Application::CAPACITY_STATUSES);
    }
}
