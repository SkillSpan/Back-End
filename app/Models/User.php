<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'google_id',
        'password',
        'phone',
        'locale',
        'status',
        'last_login_at',
        'terms_accepted_at',
        'privacy_accepted_at',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
        'privacy_accepted_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_role')
            ->withPivot('organization_id')
            ->withTimestamps();
    }

    public function organizations()
    {
        return $this->belongsToMany(Organization::class, 'organization_members')
            ->withPivot('role_in_org', 'status')
            ->withTimestamps();
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

    public function accountVerifications()
    {
        return $this->hasMany(AccountVerification::class);
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles()->where('slug', $slug)->exists();
    }

    /**
     * دايمًا نخزّن الإيميل بحروف صغيرة وبدون مسافات زايدة، عشان
     * البحث عنه لاحقًا (تسجيل دخول، تحقق، نسيت كلمة السر) ما يتأثر
     * بحساسية الأحرف الكبيرة/الصغيرة أو نسخ-لصق فيه مسافات.
     */
    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower(trim($value));
    }
}
