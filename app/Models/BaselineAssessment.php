<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BaselineAssessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_profile_id',
        'career_role_id',
        'assessment_type',
        'assessment_version',
        'question_count',
        'progress',
        'responses',
    ];

    protected $casts = [
        'progress' => 'array',
        'responses' => 'array',
        'result' => 'array',
        'normalized_skills' => 'array',
        'skill_coverage' => 'array',
        'snapshot_metadata' => 'array',
        'completed_at' => 'datetime',
    ];

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function careerRole()
    {
        return $this->belongsTo(CareerRole::class);
    }

    public function questionSnapshots()
    {
        return $this->hasMany(BaselineQuestionSnapshot::class);
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
