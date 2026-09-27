<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'conversation_id',
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

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function readByUser()
    {
        return $this->belongsTo(User::class, 'read_by');
    }

    /**
     * Scope to only messages that are not system messages.
     */
    public function scopeText($query)
    {
        return $query->where('message_type', 'text');
    }
}
