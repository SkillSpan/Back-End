<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Specialization extends Model
{
    protected $fillable = ['name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function studentProfiles()
    {
        return $this->hasMany(StudentProfile::class);
    }

    /**
     * Career roles relevant to this specialization. Many-to-many: a role
     * such as "Backend Developer" belongs to several specializations.
     */
    public function careerRoles()
    {
        return $this->belongsToMany(CareerRole::class, 'career_role_specialization')->withTimestamps();
    }
}
