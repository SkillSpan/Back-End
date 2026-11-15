<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Roadmap extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = ['student_profile_id', 'career_role_id', 'career_role_version', 'decision_snapshot_id', 'version', 'status', 'next_best_action_id', 'generated_at', 'algorithm_version', 'configuration_version', 'request_id', 'explanation', 'estimated_total_hours', 'estimated_duration_weeks', 'limitations'];

    protected $casts = [
        'generated_at' => 'datetime',
        'estimated_total_hours' => 'float',
        'estimated_duration_weeks' => 'integer',
        'limitations' => 'array',
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

    public function actions()
    {
        return $this->hasMany(RoadmapAction::class)->orderBy('order_index');
    }

    public function nextBestAction()
    {
        return $this->belongsTo(RoadmapAction::class, 'next_best_action_id');
    }
}
