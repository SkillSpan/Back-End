<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Submission extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'project_milestone_id', 'contributor_id', 'version', 'notes', 'file_path', 'link_url'];

    protected $casts = ['submitted_at' => 'datetime'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone()
    {
        return $this->belongsTo(ProjectMilestone::class, 'project_milestone_id');
    }

    public function contributor()
    {
        return $this->belongsTo(User::class, 'contributor_id');
    }

    public function evaluations()
    {
        return $this->hasMany(Evaluation::class);
    }
}
