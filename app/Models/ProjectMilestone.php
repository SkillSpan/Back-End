<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ProjectMilestone extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_MISSED = 'missed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_PROGRESS,
        self::STATUS_SUBMITTED,
        self::STATUS_COMPLETED,
        self::STATUS_MISSED,
    ];

    public const SUBMITTABLE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_PROGRESS,
        self::STATUS_MISSED,
    ];

    public const TYPE_REPOSITORY_LINK = 'repository_link';

    public const TYPE_COMPRESSED_FILE = 'compressed_file';

    public const TYPE_DOCUMENT = 'document';

    public const TYPE_OTHER_LINK = 'other_link';

    public const TYPE_ANY = 'any';

    public const SUBMISSION_TYPES = [
        self::TYPE_REPOSITORY_LINK,
        self::TYPE_COMPRESSED_FILE,
        self::TYPE_DOCUMENT,
        self::TYPE_OTHER_LINK,
        self::TYPE_ANY,
    ];

    protected $fillable = [
        'project_id',
        'title',
        'description',
        'required_deliverables',
        'submission_type',
        'due_date',
        'effective_deadline',
        'status',
        'completed_at',
        'completed_by',
        'order_index',
    ];

    protected $casts = [
        'required_deliverables' => 'array',
        'due_date' => 'date',
        'effective_deadline' => 'date',
        'completed_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function extensionRequests()
    {
        return $this->hasMany(MilestoneExtensionRequest::class);
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }

    public function changes()
    {
        return $this->hasMany(ProjectMilestoneChange::class);
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function effectiveDeadline(): ?Carbon
    {
        $deadline = $this->effective_deadline ?? $this->due_date;

        return $deadline === null ? null : Carbon::parse($deadline);
    }

    public function allowsSubmissionType(string $type): bool
    {
        return $this->submission_type === null
            || $this->submission_type === self::TYPE_ANY
            || $this->submission_type === $type;
    }

    public function isOpenForSubmission(): bool
    {
        return in_array($this->status, self::SUBMITTABLE_STATUSES, true);
    }

    public function requiredDeliverables(): array
    {
        return array_values(array_filter(
            array_map(fn ($item) => is_string($item) ? trim($item) : '', (array) ($this->required_deliverables ?? [])),
            fn (string $item) => $item !== '',
        ));
    }

    public function hasPassedDeadline(?Carbon $now = null): bool
    {
        $deadline = $this->effectiveDeadline();

        if ($deadline === null) {
            return false;
        }

        return ($now ?? Carbon::now())->endOfDay()->greaterThan($deadline->copy()->endOfDay());
    }
}
