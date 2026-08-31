<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Evaluation extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'submission_id', 'evaluator_id', 'rubric_id', 'self_reflection'];

    protected $casts = ['finalized_at' => 'datetime'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function submission()
    {
        return $this->belongsTo(Submission::class);
    }

    public function evaluator()
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function rubric()
    {
        return $this->belongsTo(Rubric::class);
    }

    public function dimensionScores()
    {
        return $this->hasMany(EvaluationDimensionScore::class);
    }

    public function disputes()
    {
        return $this->hasMany(EvaluationDispute::class);
    }
}
