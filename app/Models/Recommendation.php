<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Recommendation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'project_matching_snapshot_id',
        'type',
        'candidate_type',
        'candidate_id',
        'score',
        'factors',
        'weighted_contributions',
        'reasons',
        'limiting_factors',
        'skill_results',
        'algorithm_version',
        'configuration_version',
        'project_version',
        'eligibility_state',
        'matching_state',
        'generated_at',
    ];

    protected $casts = [
        'factors' => 'array',
        'weighted_contributions' => 'array',
        'limiting_factors' => 'array',
        'skill_results' => 'array',
        'generated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The immutable matching input set this recommendation was calculated
     * from (Task 11). Null for recommendation types that the project
     * matching flow does not produce.
     */
    public function projectMatchingSnapshot()
    {
        return $this->belongsTo(ProjectMatchingSnapshot::class);
    }

    public function candidate()
    {
        return $this->morphTo(__FUNCTION__, 'candidate_type', 'candidate_id');
    }

    public function feedbackEvents()
    {
        return $this->hasMany(FeedbackEvent::class);
    }
}
