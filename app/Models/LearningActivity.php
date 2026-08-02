<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LearningActivity extends Model
{
    use HasFactory;

    protected $fillable = ['user_id','roadmap_action_id','learning_resource_id','status','progress_value','completion_evidence','source','verification_state','occurred_at'];

    protected $casts = ['occurred_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function roadmapAction()
    {
        return $this->belongsTo(RoadmapAction::class);
    }

    public function learningResource()
    {
        return $this->belongsTo(LearningResource::class);
    }
}
