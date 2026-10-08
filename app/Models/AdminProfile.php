<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The panel user's own profile — administrator or mentor.
 *
 * Presentation only. Authorisation never reads anything here: access is decided
 * by the `user_role` pivot and by `ProfessionalProfile`, so editing this row can
 * never widen what an account can reach.
 *
 * `avatar_data` is a base64 blob (see the migration for why it is not a path)
 * and is **hidden from serialisation on purpose**. A 30 KB string inside every
 * profile payload, every list that touches a profile, and every log line that
 * dumps a model is a cost with no benefit — the avatar is served as an image
 * from its own route, where the browser can cache it.
 */
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

    /** The blob must never travel in a JSON payload or a log line. */
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

    /**
     * Cache-busting token for the avatar URL.
     *
     * The image is served from a stable URL so the browser can cache it, which
     * means the URL has to change when the bytes do — otherwise a new avatar
     * would not appear until the cache expired. `null` when there is no avatar.
     */
    public function avatarVersion(): ?string
    {
        if (! $this->hasAvatar()) {
            return null;
        }

        return (string) ($this->avatar_updated_at ?? $this->updated_at)?->getTimestamp();
    }
}
