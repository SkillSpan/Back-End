<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Roadmap extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = ['student_profile_id', 'career_role_id', 'career_role_version', 'decision_snapshot_id', 'version', 'status', 'generated_at', 'algorithm_version', 'configuration_version', 'request_id', 'explanation'];

    protected $casts = ['generated_at' => 'datetime'];

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
}
