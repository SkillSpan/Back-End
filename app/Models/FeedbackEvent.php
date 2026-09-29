<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeedbackEvent extends Model
{
    use HasFactory;

    // `occurred_at` was missing here. The column is NOT NULL with no DB default,
    // so every FeedbackEvent::create() silently dropped it and the insert died
    // with "NOT NULL constraint failed: feedback_events.occurred_at". Nothing
    // wrote to this model before US-MATCH-02, which is why the gap went
    // unnoticed. It is a plain timestamp — not an authorization-gating column —
    // so it is safe to mass-assign, the same reasoning that added `read_at` to
    // Notification::$fillable.
    protected $fillable = ['recommendation_id', 'user_id', 'event_type', 'rating_value', 'reason', 'occurred_at'];

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
