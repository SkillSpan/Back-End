<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BaselineAssessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_profile_id',
        'assessment_type',
        'assessment_version',
        'status',
        'progress',
        'responses',
        'result',
        'normalized_skills',
        'completed_at',
    ];

    protected $casts = [
        'progress' => 'array',
        'responses' => 'array',
        'result' => 'array',
        'normalized_skills' => 'array',
        'completed_at' => 'datetime',
    ];

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }
}
