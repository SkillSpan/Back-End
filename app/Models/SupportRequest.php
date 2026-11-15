<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportRequest extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const REASON_INSUFFICIENT_CONTEXT = 'insufficient_context';

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

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

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
