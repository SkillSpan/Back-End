<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReadinessResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_profile_id',
        'career_role_id',
        'career_role_version',
        'decision_snapshot_id',
        'score',
        'skill_match_component',
        'practical_experience_component',
        'assessment_reliability_component',
        'profile_completeness_component',
        'critical_cap_applied',
        'is_provisional',
        'band',
        'algorithm_version',
        'configuration_version',
        'request_id',
        'calculated_at',
        'snapshot',
    ];

    protected $casts = [
        'critical_cap_applied' => 'boolean',
        'is_provisional' => 'boolean',
        'calculated_at' => 'datetime',
        'snapshot' => 'array',
    ];

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function careerRole()
    {
        return $this->belongsTo(CareerRole::class);
    }

    public function decisionSnapshot()
    {
        return $this->belongsTo(DecisionSnapshot::class);
    }
}
