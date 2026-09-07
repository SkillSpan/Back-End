<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BaselineAssessmentItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'assessment_version',
        'item_id',
        'item_type',
        'skill_id',
        'options',
        'correct_answer',
        'scoring_rule',
        'weight',
        'is_active',
    ];

    protected $casts = [
        'options' => 'array',
        'scoring_rule' => 'array',
        'weight' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }
}
