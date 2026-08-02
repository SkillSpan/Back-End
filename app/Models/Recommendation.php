<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Recommendation extends Model
{
    use HasFactory;

    protected $fillable = ['user_id','type','candidate_type','candidate_id','score','factors','reasons','algorithm_version','eligibility_state','generated_at'];

    protected $casts = ['factors' => 'array', 'generated_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
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
