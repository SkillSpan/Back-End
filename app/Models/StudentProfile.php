<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentProfile extends Model
{
    use HasFactory;

    protected $fillable = ['university_name', 'student_university_number', 'specialization', 'academic_level', 'expected_graduation', 'bio', 'career_status', 'interests', 'availability', 'weekly_availability_hours', 'preferred_work_type', 'primary_career_role_id', 'consent_given', 'avatar_data', 'avatar_mime', 'avatar_updated_at'];

    protected $casts = ['interests' => 'array', 'graduation_status' => 'boolean', 'graduation_date' => 'date', 'expected_graduation' => 'integer', 'consent_given' => 'boolean', 'weekly_availability_hours' => 'float', 'avatar_updated_at' => 'datetime'];

    /** The blob must never travel in a JSON payload or a log line. */
    protected $hidden = ['avatar_data'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function hasAvatar(): bool
    {
        return is_string($this->avatar_data) && $this->avatar_data !== '';
    }

    /**
     * Cache-busting token for the avatar URL.
     *
     * The image is served from a stable URL so the browser can cache it, which
     * means the URL has to change when the bytes do. `null` when there is no
     * avatar.
     */
    public function avatarVersion(): ?string
    {
        if (! $this->hasAvatar()) {
            return null;
        }

        return (string) ($this->avatar_updated_at ?? $this->updated_at)?->getTimestamp();
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

    public function projectMatchingSnapshots()
    {
        return $this->hasMany(ProjectMatchingSnapshot::class);
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
