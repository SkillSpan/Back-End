<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectTeam extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'name'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function members()
    {
        return $this->hasMany(ProjectTeamMember::class);
    }
}
