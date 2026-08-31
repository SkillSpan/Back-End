<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectMilestone extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'title', 'description', 'required_deliverables', 'due_date', 'effective_deadline', 'order_index'];

    protected $casts = ['required_deliverables' => 'array', 'due_date' => 'date', 'effective_deadline' => 'date'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function extensionRequests()
    {
        return $this->hasMany(MilestoneExtensionRequest::class);
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }
}
