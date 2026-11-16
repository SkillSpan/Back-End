<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rubric extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RETIRED = 'retired';

    public const STATUS_DEACTIVATED = 'deactivated';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
        self::STATUS_RETIRED,
        self::STATUS_DEACTIVATED,
    ];

    public const DIMENSIONS = [
        'quality',
        'commitment',
        'communication',
        'collaboration',
        'creativity',
        'problem_solving',
        'delivery',
    ];

    protected $fillable = [
        'family_id',
        'title',
        'description',
        'applicable_project_types',
        'score_min',
        'score_max',
        'version',
        'status',
        'created_by',
        'approved_by',
        'approval_reason',
        'effective_date',
        'published_at',
        'deactivated_at',
        'deactivation_reason',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'effective_date' => 'date',
        'applicable_project_types' => 'array',
        'score_min' => 'float',
        'score_max' => 'float',
        'version' => 'integer',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function family()
    {
        return $this->belongsTo(Rubric::class, 'family_id');
    }

    public function versions()
    {
        return $this->hasMany(Rubric::class, 'family_id')->orderBy('version');
    }

    public function dimensions()
    {
        return $this->hasMany(RubricDimension::class)->orderBy('order_index');
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function evaluations()
    {
        return $this->hasMany(Evaluation::class);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isImmutable(): bool
    {
        return in_array($this->status, [self::STATUS_PUBLISHED, self::STATUS_DEACTIVATED, self::STATUS_RETIRED], true);
    }

    public function isSelectable(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function totalWeight(): float
    {
        return (float) $this->dimensions()->sum('weight');
    }
}
