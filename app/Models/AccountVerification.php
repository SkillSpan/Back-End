<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountVerification extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'organization_id', 'channel', 'expires_at'];

    /**
     * `challenge_state` holds the hashed OTP.
     *
     * A bcrypt hash of a six-digit code is only a million candidates —
     * recoverable offline in seconds — so it is treated as a secret rather than
     * as a derived value, exactly as AuthSession hides `token_hash`. The model
     * is not serialised into any response today; this is the guard for the day
     * one returns it.
     */
    protected $hidden = ['challenge_state'];

    protected $casts = ['expires_at' => 'datetime', 'decided_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
