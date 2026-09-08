<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectRequiredSkill extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'skill_id', 'minimum_level', 'is_critical_entry'];

    protected $casts = ['is_critical_entry' => 'boolean'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }
}
