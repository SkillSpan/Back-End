<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SkillEvidence extends Model
{
    use HasFactory;

    protected $fillable = ['student_profile_id','skill_id','source','value','normalized_value','reference','evidence_date','verification_status','reviewer_id','reviewer_notes','recency_factor','source_record_type','source_record_id'];

    protected $casts = ['evidence_date' => 'date'];

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function sourceRecord()
    {
        return $this->morphTo(__FUNCTION__, 'source_record_type', 'source_record_id');
    }
}
