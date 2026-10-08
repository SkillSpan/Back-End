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
        'question_text',
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

    /**
     * The frozen question snapshots that reference this item.
     *
     * Used by the admin question bank to refuse deleting a question that is
     * already part of an assessment: the snapshot FK cascades on delete, so
     * removing the item would silently erase the questions a learner was
     * (or is being) assessed on.
     */
    public function questionSnapshots()
    {
        return $this->hasMany(BaselineQuestionSnapshot::class, 'baseline_assessment_item_id');
    }
}
