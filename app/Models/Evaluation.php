<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Evaluation extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_MODERATION = 'moderation';

    public const STATUS_FINALIZED = 'finalized';

    public const STATUS_DISPUTED = 'disputed';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_VOIDED = 'voided';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_MODERATION,
        self::STATUS_FINALIZED,
        self::STATUS_DISPUTED,
        self::STATUS_SUPERSEDED,
        self::STATUS_VOIDED,
    ];

    public const EDITABLE_STATUSES = [
        self::STATUS_DRAFT,
    ];

    public const STUDENT_VISIBLE_STATUSES = [
        self::STATUS_FINALIZED,
        self::STATUS_DISPUTED,
        self::STATUS_SUPERSEDED,
        self::STATUS_VOIDED,
    ];

    public const EVALUATOR_TYPE_MENTOR = 'mentor';

    public const EVALUATOR_TYPE_REVIEWER = 'reviewer';

    public const EVALUATOR_TYPE_COMPANY = 'company_evaluator';

    public const EVALUATOR_TYPE_DELEGATE = 'delegate';

    public const EVALUATOR_TYPES = [
        self::EVALUATOR_TYPE_MENTOR,
        self::EVALUATOR_TYPE_REVIEWER,
        self::EVALUATOR_TYPE_COMPANY,
        self::EVALUATOR_TYPE_DELEGATE,
    ];

    protected $fillable = [
        'project_id',
        'submission_id',
        'participant_id',
        'evaluator_id',
        'evaluator_type',
        'rubric_id',
        'rubric_version',
        'status',
        'overall_score',
        'team_outcome',
        'individual_contribution',
        'improvement_guidance',
        'confidential_notes',
        'conflict_declared',
        'conflict_declared_at',
        'conflict_mitigation_approved',
        'self_reflection',
        'submitted_at',
        'finalized_at',
        'finalized_by',
        'calculation_inputs',
        'supersedes_evaluation_id',
        'superseded_by_evaluation_id',
        'moderation_reason',
        'moderation_status',
    ];

    protected $casts = [
        'finalized_at' => 'datetime',
        'submitted_at' => 'datetime',
        'conflict_declared' => 'boolean',
        'conflict_declared_at' => 'datetime',
        'conflict_mitigation_approved' => 'boolean',
        'calculation_inputs' => 'array',
        'overall_score' => 'float',
        'rubric_version' => 'integer',
    ];

    protected $hidden = ['confidential_notes'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function submission()
    {
        return $this->belongsTo(Submission::class);
    }

    public function participant()
    {
        return $this->belongsTo(User::class, 'participant_id');
    }

    public function evaluator()
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function rubric()
    {
        return $this->belongsTo(Rubric::class);
    }

    public function dimensionScores()
    {
        return $this->hasMany(EvaluationDimensionScore::class);
    }

    public function disputes()
    {
        return $this->hasMany(EvaluationDispute::class);
    }

    public function supersedes()
    {
        return $this->belongsTo(Evaluation::class, 'supersedes_evaluation_id');
    }

    public function supersededBy()
    {
        return $this->belongsTo(Evaluation::class, 'superseded_by_evaluation_id');
    }

    public function isFinalized(): bool
    {
        return in_array($this->status, self::STUDENT_VISIBLE_STATUSES, true);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true);
    }
}
