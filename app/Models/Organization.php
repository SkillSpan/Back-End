<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = ['name','type','verification_status','verified_at','verified_by','contact_email','contact_phone','website','description','industry','company_size','country','city','address','postal_code'];

    protected $casts = ['verified_at' => 'datetime'];

    public function members()
    {
        return $this->belongsToMany(User::class, 'organization_members')->withPivot('role_in_org', 'status')->withTimestamps();
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * The registration proof/certificate uploaded by the organization's admin
     * during registration (see AuthService::uploadProofFile). Proof files are
     * not linked to the organization directly, so we resolve them through the
     * organization's admin member.
     */
    public function proofFile(): ?UploadedFile
    {
        $adminUserId = $this->members()
            ->wherePivot('role_in_org', 'admin')
            ->orderBy('organization_members.created_at')
            ->value('users.id');

        if (! $adminUserId) {
            return null;
        }

        return UploadedFile::where('user_id', $adminUserId)
            ->where('type', 'certificate')
            ->latest()
            ->first();
    }
}
