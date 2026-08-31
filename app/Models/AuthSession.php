<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuthSession extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'device', 'user_agent', 'ip_address', 'issued_at', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected $casts = ['issued_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
