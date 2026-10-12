<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SkillEvidence extends Model
{
    use HasFactory;

    /*
     * ------------------------------------------------------------------
     * Verification states (BA-02)
     * ------------------------------------------------------------------
     *
     * These constants are the single source of truth for the values the
     * `skill_evidences.verification_status` enum accepts and for the review
     * transitions EvidenceController::review() allows. The database enum
     * (2026_10_12_000001) must stay in sync with STATUSES.
     *
     * SRS v1.1 §13.6 (Table 75) lists the Evidence dictionary as
     * "submitted, pending_review, verified, rejected, expired, superseded,
     * voided". The backend keeps its own, already-shipped names rather than
     * renaming live columns: `pending` is the single pre-review bucket that
     * covers the SRS "submitted / pending_review" pair, and `expired` is
     * present. SKL-05 (Must) additionally requires the reviewer to be able to
     * "request clarification", which is `revision`.
     */

    /** Awaiting a reviewer decision. The state every submission starts in. */
    public const STATUS_PENDING = 'pending';

    /** Approved. The only state that contributes to skill level. */
    public const STATUS_VERIFIED = 'verified';

    /** Denied. Contributes nothing. */
    public const STATUS_REJECTED = 'rejected';

    /** No longer valid (e.g. a certificate past its validity window). */
    public const STATUS_EXPIRED = 'expired';

    /** The reviewer asked the learner to clarify/replace the evidence. */
    public const STATUS_REVISION = 'revision';

    /**
     * Every value the `verification_status` column accepts, in the exact
     * order declared by the migration (order matters for MySQL ENUM storage).
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VERIFIED,
        self::STATUS_REJECTED,
        self::STATUS_EXPIRED,
        self::STATUS_REVISION,
    ];

    /**
     * The values an administrator may set through
     * PUT /api/v1/evidence/{id}/review.
     */
    public const REVIEW_OUTCOMES = [
        self::STATUS_VERIFIED,
        self::STATUS_REJECTED,
        self::STATUS_REVISION,
    ];

    /**
     * The states a review may act on — i.e. evidence that is still awaiting a
     * decision. Terminal states (verified / rejected / expired) cannot be
     * silently rewritten; SRS BR-EVA-06 requires a correction to supersede or
     * revise, never to rewrite history.
     */
    public const REVIEWABLE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_REVISION,
    ];

    protected $table = 'skill_evidences';

    protected $fillable = [
        'student_profile_id',
        'skill_id',
        'source',
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
}
