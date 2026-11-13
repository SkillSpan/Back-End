<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProfessionalRecordShareLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_profile_id',
        'token_hash',
        'sections',
        'expires_at',
        'revoked_at',
        'last_accessed_at',
        'access_count',
    ];

    protected $casts = [
        'sections' => 'array',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'access_count' => 'integer',
    ];

    protected $hidden = ['token_hash'];

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function accesses()
    {
        return $this->hasMany(ProfessionalRecordShareAccess::class, 'share_link_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }
}
