<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = ['organization_id', 'owner_id', 'type', 'domain', 'title', 'description', 'objectives', 'learning_outcomes', 'difficulty', 'work_mode', 'role', 'schedule', 'capacity', 'min_team_size', 'start_date', 'end_date', 'application_deadline', 'status', 'confidentiality', 'rubric_id', 'version'];

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

    /**
     * US-MATCH-02 — the roles a learner can select when applying.
     *
     * NOTE: project targeting is NOT modelled here. Relevance is decided by
     * career role + required skills + learner skill levels + difficulty, which
     * already flow through ProjectMatchingService / the FastAPI
     * `target_career_role` payload. Academic specialization is deliberately not
     * a project column or an application gate.
     */
    public function projectRoles()
    {
        return $this->hasMany(ProjectRole::class);
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

    public function matchingSnapshots()
    {
        return $this->hasMany(ProjectMatchingSnapshot::class);
    }

    /**
     * Applications that currently occupy a capacity slot.
     *
     * WHICH statuses count is a capacity POLICY, not a schema fact. This
     * relation only exposes the accepted ones so the eventual policy can be
     * swapped without touching the schema. See the capacity policy question in
     * US-MATCH-02_APPLICATION_WORKFLOW_REPORT.md — the rule is not yet
     * confirmed.
     */
    public function acceptedApplications()
    {
        return $this->hasMany(Application::class)
            ->whereIn('status', Application::CAPACITY_STATUSES);
    }
}
