<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectEligibilityConstraint extends Model
{
    use HasFactory;

    protected $fillable = ['project_id','constraint_type','value'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
