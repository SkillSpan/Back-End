<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * US-MATCH-02 — a selectable role a learner applies for within one project.
 *
 * Introduced because no project-role schema existed (see
 * 2026_09_29_000002_create_project_roles_table). Capacity is intentionally not
 * modelled here: the existing source of truth is the global projects.capacity.
 */
class ProjectRole extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'title', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    /**
     * A role is available when the owner has not deactivated it.
     * Capacity is enforced separately, against projects.capacity.
     */
    public function isAvailable(): bool
    {
        return (bool) $this->is_active;
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_active', true);
    }
}
