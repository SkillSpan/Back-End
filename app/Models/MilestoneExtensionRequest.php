<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MilestoneExtensionRequest extends Model
{
    use HasFactory;

    protected $fillable = ['project_milestone_id', 'requested_by', 'reason', 'new_due_date'];

    protected $casts = ['new_due_date' => 'date', 'decided_at' => 'datetime'];

    public function milestone()
    {
        return $this->belongsTo(ProjectMilestone::class, 'project_milestone_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
