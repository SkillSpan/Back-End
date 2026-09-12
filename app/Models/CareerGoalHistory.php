<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CareerGoalHistory extends Model
{
    use HasFactory;

    protected $table = 'career_goal_history';

    protected $fillable = ['student_profile_id', 'career_role_id', 'career_role_version', 'set_at', 'replaced_at'];

    protected $casts = ['set_at' => 'datetime', 'replaced_at' => 'datetime'];

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function careerRole()
    {
        return $this->belongsTo(CareerRole::class);
    }
}
