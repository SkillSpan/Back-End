<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'organization_id',
        'category',
        'channel',
        'title',
        'body',
        'link',
        'entity_type',
        'entity_id',
        'event_key',
        'read_at',
    ];

    protected $casts = ['read_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The organization this notification belongs to, when there is one
     * (US-COMP-03). Null for mentor/student traffic, which is not
     * organization-scoped.
     */
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The record the notification is about — a project, an application, a
     * mentorship. Nullable: older rows only carry a `link`.
     */
    public function entity()
    {
        return $this->morphTo();
    }
}
