<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProfessionalRecordItem extends Model
{
    use HasFactory;

    public const TYPE_PROJECT = 'project';

    public const TYPE_EVALUATION = 'evaluation';

    public const TYPE_SKILL = 'skill';

    public const TYPE_ACHIEVEMENT = 'achievement';

    public const TYPE_PROFILE = 'profile';

    public const TYPES = [
        self::TYPE_PROJECT,
        self::TYPE_EVALUATION,
        self::TYPE_SKILL,
        self::TYPE_ACHIEVEMENT,
        self::TYPE_PROFILE,
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_VOIDED = 'voided';

    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_SUPERSEDED,
        self::STATUS_VOIDED,
        self::STATUS_REVOKED,
    ];

    public const VERIFICATION_SELF_DECLARED = 'self_declared';

    public const VERIFICATION_VERIFIED = 'verified';

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_ORGANIZATION_ONLY = 'organization_only';

    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITIES = [
        self::VISIBILITY_PUBLIC,
        self::VISIBILITY_ORGANIZATION_ONLY,
        self::VISIBILITY_PRIVATE,
    ];

    public const SECTIONS = [
        self::TYPE_PROFILE,
        self::TYPE_PROJECT,
        self::TYPE_EVALUATION,
        self::TYPE_SKILL,
        self::TYPE_ACHIEVEMENT,
    ];

    protected $fillable = [
        'student_profile_id',
        'source_type',
        'source_id',
        'item_type',
        'source_version',
        'project_type',
        'role',
        'contribution',
        'issuer',
        'supporting_reference',
        'title',
        'description',
        'verification_state',
        'status',
        'idempotency_key',
        'evaluator_id',
        'visibility',
        'achieved_at',
    ];

    protected $casts = [
        'achieved_at' => 'date',
    ];

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function evaluator()
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function source()
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function isVerified(): bool
    {
        return $this->verification_state === self::VERIFICATION_VERIFIED;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function verificationLabel(): string
    {
        return $this->isVerified() ? 'verified' : 'self_declared';
    }
}
