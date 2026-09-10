<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentProfile extends Model
{
    use HasFactory;

    protected $fillable = ['university_name', 'student_university_number', 'specialization', 'academic_level', 'expected_graduation', 'bio', 'career_status', 'interests', 'availability', 'preferred_work_type', 'primary_career_role_id', 'consent_given'];

    protected $casts = ['interests' => 'array', 'graduation_status' => 'boolean', 'graduation_date' => 'date', 'expected_graduation' => 'integer', 'consent_given' => 'boolean'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function primaryCareerRole()
    {
        return $this->belongsTo(CareerRole::class, 'primary_career_role_id');
    }

    public function careerGoalHistory()
    {
        return $this->hasMany(CareerGoalHistory::class);
    }

    public function skillEvidences()
    {
        return $this->hasMany(SkillEvidence::class);
    }

    public function skillEvaluations()
    {
        return $this->hasMany(SkillEvaluation::class);
    }

    public function readinessResults()
    {
        return $this->hasMany(ReadinessResult::class);
    }

    public function decisionSnapshots()
    {
        return $this->hasMany(DecisionSnapshot::class);
    }

    public function roadmaps()
    {
        return $this->hasMany(Roadmap::class);
    }

    public function professionalRecordItems()
    {
        return $this->hasMany(ProfessionalRecordItem::class);
    }
}
