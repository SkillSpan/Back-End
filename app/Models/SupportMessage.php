<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportMessage extends Model
{
    use HasFactory;

    public const TYPE_TEXT = 'text';

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
