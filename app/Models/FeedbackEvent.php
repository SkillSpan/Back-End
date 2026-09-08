<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeedbackEvent extends Model
{
    use HasFactory;

    protected $fillable = ['recommendation_id', 'user_id', 'event_type', 'rating_value', 'reason'];

    protected $casts = ['occurred_at' => 'datetime'];

    public function recommendation()
    {
        return $this->belongsTo(Recommendation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
