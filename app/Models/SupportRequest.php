<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * US-REC-01 follow-up — an escalated assistant conversation.
 *
 * Created when the assistant answers `insufficient_context` and the learner
 * accepts the handoff, or when the learner asks for a human outright. A support
 * person (a mentor) picks it up from the admin panel and replies in the thread.
 *
 * `transcript` is a snapshot of the assistant exchange at handoff time. It is
 * the only place the learner's own words are persisted, and it exists because a
 * human needs them to help — `assistant_interactions` still stores no question
 * text (§12.5 data minimisation).
 */
class SupportRequest extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** Waiting for a support person to pick it up. */
    public const STATUS_PENDING = 'pending';

    /** Claimed by a support person. */
    public const STATUS_ASSIGNED = 'assigned';

    /** Answered and closed by the support person. */
    public const STATUS_RESOLVED = 'resolved';

    /** Closed without resolution (duplicate, spam, learner gone). */
    public const STATUS_CLOSED = 'closed';

    /** The assistant could not answer the question. */
    public const REASON_INSUFFICIENT_CONTEXT = 'insufficient_context';

    /** The learner asked for a human without waiting for the assistant. */
    public const REASON_LEARNER_REQUESTED = 'learner_requested';

    protected $fillable = [
        'user_id',
        'student_profile_id',
        'assigned_to',
        'status',
        'reason',
        'subject',
        'transcript',
        'source_interaction_id',
        'assigned_at',
        'resolved_at',
        'last_message_at',
    ];

    protected $casts = [
        'transcript' => 'array',
        'assigned_at' => 'datetime',
        'resolved_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    /** The support person (mentor) who owns this request. */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function sourceInteraction(): BelongsTo
    {
        return $this->belongsTo(AssistantInteraction::class, 'source_interaction_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->oldest('created_at');
    }

    public function messagesLatestFirst(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->latest('created_at');
    }

    /** Requests nobody has claimed yet. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /** Requests still needing attention (pending or claimed). */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_ASSIGNED]);
    }

    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where('assigned_to', $userId);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_ASSIGNED], true);
    }
}
