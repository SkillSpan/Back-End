<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Conversation extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'mentor_student_connection_id',
        'status',
        'created_by',
        'last_message_at',
        'retention_expires_at',
        'archived_reason',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'retention_expires_at' => 'datetime',
    ];

    public function connection()
    {
        return $this->belongsTo(MentorStudentConnection::class, 'mentor_student_connection_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function messagesLatestFirst()
    {
        return $this->hasMany(Message::class)->latest('created_at');
    }

    /**
     * Users who have removed this conversation from their own list.
     *
     * Deliberately NOT a delete: the conversation, its messages and the audit
     * trail stay intact for the other participant. See the
     * `conversation_user_hides` migration for the reasoning.
     */
    public function hiddenFor()
    {
        return $this->belongsToMany(User::class, 'conversation_user_hides')
            ->withPivot('hidden_at')
            ->withTimestamps();
    }

    /**
     * Scope to only active conversations.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
