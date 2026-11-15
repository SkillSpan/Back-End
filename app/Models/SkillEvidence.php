<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SkillEvidence extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVISION = 'revision';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VERIFIED,
        self::STATUS_REJECTED,
        self::STATUS_EXPIRED,
        self::STATUS_REVISION,
    ];

    public const REVIEW_OUTCOMES = [
        self::STATUS_VERIFIED,
        self::STATUS_REJECTED,
        self::STATUS_REVISION,
    ];

    public const REVIEWABLE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_REVISION,
    ];

    public const TYPE_GITHUB_REPOSITORY = 'github_repository';

    public const TYPE_LIVE_DEPLOYMENT = 'live_deployment';

    public const TYPE_DESIGN_FILE = 'design_file';

    public const TYPE_DOCUMENT = 'document';

    public const EVIDENCE_TYPES = [
        self::TYPE_GITHUB_REPOSITORY,
        self::TYPE_LIVE_DEPLOYMENT,
        self::TYPE_DESIGN_FILE,
        self::TYPE_DOCUMENT,
    ];

    public const URL_BASED_TYPES = [
        self::TYPE_GITHUB_REPOSITORY,
        self::TYPE_LIVE_DEPLOYMENT,
        self::TYPE_DESIGN_FILE,
    ];

    public const FILE_BASED_TYPES = [
        self::TYPE_DOCUMENT,
    ];

    public const LEARNER_SUBMISSION_SOURCE = 'certificate';

    protected $table = 'skill_evidences';

    protected $fillable = [
        'student_profile_id',
        'skill_id',
        'source',
        'evidence_type',
        'value',
        'normalized_value',
        'reference',
        'description',
        'evidence_date',
        'verification_status',
        'reviewer_id',
        'reviewer_notes',
        'recency_factor',
        'source_record_type',
        'source_record_id',
    ];

    protected $casts = [
        'evidence_date' => 'date',
    ];

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function sourceRecord()
    {
        return $this->morphTo(__FUNCTION__, 'source_record_type', 'source_record_id');
    }

    public function isUrlBased(): bool
    {
        return in_array($this->evidence_type, self::URL_BASED_TYPES, true);
    }

    public function isFileBased(): bool
    {
        return in_array($this->evidence_type, self::FILE_BASED_TYPES, true);
    }
}
