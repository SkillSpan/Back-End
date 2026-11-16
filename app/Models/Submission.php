<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Submission extends Model
{
    use HasFactory;

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_ACCEPTED = 'accepted';

    public const TYPE_REPOSITORY_LINK = 'repository_link';

    public const TYPE_COMPRESSED_FILE = 'compressed_file';

    public const TYPE_DOCUMENT = 'document';

    public const TYPE_OTHER_LINK = 'other_link';

    public const TYPES = [
        self::TYPE_REPOSITORY_LINK,
        self::TYPE_COMPRESSED_FILE,
        self::TYPE_DOCUMENT,
        self::TYPE_OTHER_LINK,
    ];

    public const FILE_TYPES = [
        self::TYPE_COMPRESSED_FILE,
        self::TYPE_DOCUMENT,
    ];

    public const FIRST_PASS_PENDING = 'pending';

    public const FIRST_PASS_PASSED = 'passed';

    public const FIRST_PASS_FAILED = 'failed';

    protected $fillable = [
        'project_id',
        'project_milestone_id',
        'project_team_id',
        'contributor_id',
        'version',
        'submission_type',
        'notes',
        'file_path',
        'original_filename',
        'file_size',
        'content_type',
        'link_url',
        'status',
        'is_current',
        'idempotency_key',
        'first_pass_status',
        'submitted_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'is_current' => 'boolean',
        'file_size' => 'integer',
        'version' => 'integer',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone()
    {
        return $this->belongsTo(ProjectMilestone::class, 'project_milestone_id');
    }

    public function team()
    {
        return $this->belongsTo(ProjectTeam::class, 'project_team_id');
    }

    public function contributor()
    {
        return $this->belongsTo(User::class, 'contributor_id');
    }

    public function evaluations()
    {
        return $this->hasMany(Evaluation::class);
    }

    public function firstPassCheck()
    {
        return $this->hasOne(SubmissionFirstPassCheck::class);
    }

    public function isFileBased(): bool
    {
        return in_array($this->submission_type, self::FILE_TYPES, true);
    }

    public function reference(): ?string
    {
        return $this->isFileBased() ? $this->file_path : $this->link_url;
    }
}
