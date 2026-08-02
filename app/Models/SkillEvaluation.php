<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SkillEvaluation extends Model
{
    use HasFactory;

    protected $fillable = ['student_profile_id','skill_id','level','confidence','algorithm_version','calculated_at','snapshot'];

    protected $casts = ['calculated_at' => 'datetime', 'snapshot' => 'array'];

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }
}
