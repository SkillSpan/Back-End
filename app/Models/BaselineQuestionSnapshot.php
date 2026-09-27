<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BaselineQuestionSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'baseline_assessment_id',
        'baseline_assessment_item_id',
        'career_role_id',
        'skill_id',
        'item_id',
        'item_type',
        'question_text',
        'options',
        'importance_weight',
        'is_critical',
    ];

    protected $casts = [
        'is_critical' => 'boolean',
        'options' => 'array',
    ];

    public function baselineAssessment()
    {
        return $this->belongsTo(BaselineAssessment::class);
    }

    public function item()
    {
        return $this->belongsTo(BaselineAssessmentItem::class, 'baseline_assessment_item_id');
    }

    public function careerRole()
    {
        return $this->belongsTo(CareerRole::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }
}
