<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    public function isAvailable(): bool
    {
        return (bool) $this->is_active;
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_active', true);
    }
}
