<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'display_title',
        'bio',
        'age',
        'avatar_data',
        'avatar_mime',
        'avatar_updated_at',
    ];

    protected $casts = [
        'age' => 'integer',
        'avatar_updated_at' => 'datetime',
    ];

    protected $hidden = [
        'avatar_data',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasAvatar(): bool
    {
        return is_string($this->avatar_data) && $this->avatar_data !== '';
    }

    public function avatarVersion(): ?string
    {
        if (! $this->hasAvatar()) {
            return null;
        }

        return (string) ($this->avatar_updated_at ?? $this->updated_at)?->getTimestamp();
    }
}
