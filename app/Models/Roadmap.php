<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Roadmap extends Model
{
    use HasFactory;

    protected $fillable = ['student_profile_id','career_role_id','career_role_version','version','status','generated_at'];

    protected $casts = ['generated_at' => 'datetime'];

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function careerRole()
    {
        return $this->belongsTo(CareerRole::class);
    }

    public function actions()
    {
        return $this->hasMany(RoadmapAction::class);
    }
}
