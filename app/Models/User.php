<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['name','email','password','phone','locale','status','last_login_at'];

    protected $casts = ['email_verified_at' => 'datetime', 'last_login_at' => 'datetime', 'password' => 'hashed'];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_role')->withPivot('organization_id')->withTimestamps();
    }

    public function organizations()
    {
        return $this->belongsToMany(Organization::class, 'organization_members')->withPivot('role_in_org', 'status')->withTimestamps();
    }

    public function studentProfile()
    {
        return $this->hasOne(StudentProfile::class);
    }

    public function professionalProfile()
    {
        return $this->hasOne(ProfessionalProfile::class);
    }

    public function ownedProjects()
    {
        return $this->hasMany(Project::class, 'owner_id');
    }

    public function applications()
    {
        return $this->hasMany(Application::class, 'applicant_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function authSessions()
    {
        return $this->hasMany(AuthSession::class);
    }
}
