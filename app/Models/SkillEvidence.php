<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SkillEvidence extends Model
{
    use HasFactory;

    protected $table = 'skill_evidences';

    protected $fillable = [
        'learner_id',
        'skill_id',
        'evidence_url',
        'evidence_file',
        'description',
        'evidence_date',
        'status',
        'reviewer_id',
        'review_notes',
        'reviewed_at',
    ];

    protected $casts = [
        'evidence_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function learner()
    {
        return $this->belongsTo(User::class, 'learner_id');
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}