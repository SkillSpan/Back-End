<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MentorStudentConnection extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'mentor_id',
        'student_id',
        'project_id',
        'status',
        'initiated_by',
        'disconnected_reason',
        'disconnected_at',
    ];

    protected $casts = [
        'disconnected_at' => 'datetime',
    ];

    public function mentor()
    {
        return $this->belongsTo(User::class, 'mentor_id');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }

    public function scopeForMentor($query, int $mentorId)
    {
        return $query->where('mentor_id', $mentorId);
    }

    public function scopeForStudent($query, int $studentId)
    {
        return $query->where('student_id', $studentId);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['pending', 'active']);
    }
}
