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

    /*
     * ------------------------------------------------------------------
     * Evidence types — the SHAPE of the submitted proof
     * ------------------------------------------------------------------
     *
     * `evidence_type` is a separate axis from `source` and must not be
     * confused with it:
     *
     *   source        — which weighted assessment channel the record feeds
     *                   (self_assessment / assessment_test /
     *                   project_performance / expert_evaluation /
     *                   certificate). Weights live in
     *                   config('services.evidence.source_weights') and drive
     *                   SkillEvaluationService.
     *
     *   evidence_type — what the learner actually attached: a repository, a
     *                   running deployment, a design file, or an uploaded
     *                   document. It carries no weight; it tells the reviewer
     *                   (and the learner) how the proof should be read.
     *
     * Every learner submission keeps `source = 'certificate'` — the channel for
     * a self-submitted artifact that no one has assessed yet. Mapping GitHub
     * evidence to `project_performance` would silently multiply its confidence
     * contribution by 7 (0.05 → 0.35), which is a product/Data-Science decision
     * and not something this column may imply.
     */
    public const TYPE_GITHUB_REPOSITORY = 'github_repository';

    public const TYPE_LIVE_DEPLOYMENT = 'live_deployment';

    public const TYPE_DESIGN_FILE = 'design_file';

    public const TYPE_DOCUMENT = 'document';

    /**
     * Every value `evidence_type` accepts. Validation reads this list, so an
     * unknown type is a 422 rather than a new column value.
     *
     * The column is NULLABLE for records written before the column existed;
     * NULL means "type not recorded", never "some other type".
     */
    public const EVIDENCE_TYPES = [
        self::TYPE_GITHUB_REPOSITORY,
        self::TYPE_LIVE_DEPLOYMENT,
        self::TYPE_DESIGN_FILE,
        self::TYPE_DOCUMENT,
    ];

    /**
     * Types proven by a link. `evidence_url` is required for these and an
     * uploaded file is refused, so a request can never carry a proof the
     * backend then silently discards.
     */
    public const URL_BASED_TYPES = [
        self::TYPE_GITHUB_REPOSITORY,
        self::TYPE_LIVE_DEPLOYMENT,
        self::TYPE_DESIGN_FILE,
    ];

    /**
     * Types proven by an uploaded file. `evidence_file` is required for these
     * and `evidence_url` is refused.
     */
    public const FILE_BASED_TYPES = [
        self::TYPE_DOCUMENT,
    ];

    /** The evidence source every learner submission is recorded under. */
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

    /** Whether this record's proof is a link rather than an uploaded file. */
    public function isUrlBased(): bool
    {
        return in_array($this->evidence_type, self::URL_BASED_TYPES, true);
    }

    /** Whether this record's proof is an uploaded file. */
    public function isFileBased(): bool
    {
        return in_array($this->evidence_type, self::FILE_BASED_TYPES, true);
    }
}
