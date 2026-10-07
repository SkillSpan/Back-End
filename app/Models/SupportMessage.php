<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message inside a support request thread.
 *
 * `sender_id` is the learner for inbound messages and a mentor/admin for
 * outbound ones, so a thread renders the same way in both directions.
 */
class SupportMessage extends Model
{
    use HasFactory;

    /** Written by a person. */
    public const TYPE_TEXT = 'text';

    /**
     * Written by the platform, not a person — the handoff marker
     * ("Transferring you to technical support") and resolution notes. Kept
     * distinct so the thread never implies a human said something they did not.
     */
    public const TYPE_SYSTEM = 'system';

    protected $fillable = [
        'support_request_id',
        'sender_id',
        'body',
        'message_type',
        'metadata',
        'read_at',
        'read_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'read_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(SupportRequest::class, 'support_request_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function readByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'read_by');
    }
}
