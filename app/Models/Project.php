<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = ['organization_id','owner_id','type','domain','title','description','objectives','learning_outcomes','difficulty','work_mode','role','schedule','capacity','min_team_size','application_deadline','start_date','end_date','status','confidentiality','rubric_id','cloned_from_project_id','approved_by','approved_at'];

    protected $casts = ['learning_outcomes' => 'array', 'application_deadline' => 'date', 'start_date' => 'date', 'end_date' => 'date', 'approved_at' => 'datetime'];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function rubric()
    {
        return $this->belongsTo(Rubric::class);
    }

    public function clonedFrom()
    {
        return $this->belongsTo(Project::class, 'cloned_from_project_id');
    }

    public function requiredSkills()
    {
        return $this->hasMany(ProjectRequiredSkill::class);
    }

    public function eligibilityConstraints()
    {
        return $this->hasMany(ProjectEligibilityConstraint::class);
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    public function teams()
    {
        return $this->hasMany(ProjectTeam::class);
    }

    public function milestones()
    {
        return $this->hasMany(ProjectMilestone::class);
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }

    public function evaluations()
    {
        return $this->hasMany(Evaluation::class);
    }
}
