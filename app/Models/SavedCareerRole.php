<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedCareerRole extends Model
{
    protected $fillable = ['user_id', 'career_role_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function careerRole()
    {
        return $this->belongsTo(CareerRole::class);
    }
}
