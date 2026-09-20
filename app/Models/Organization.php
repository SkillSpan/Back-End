<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'type', 'contact_email', 'contact_phone', 'website', 'description', 'industry', 'company_size', 'country', 'city', 'address', 'postal_code'];

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
     * during registration (see AuthService::uploadProofFile).
     *
     * Resolved as the most recent `certificate` record on this organization's
     * own polymorphic `files()` relation — `fileable_type` is Organization, so
     * the lookup is a direct morphMany and does not go through the uploading
     * member.
     *
     * This returns the database row only. It says nothing about whether the
     * file still exists on the configured disk: a row can outlive its file
     * (a container filesystem without a persistent volume drops uploads on
     * every deploy). Check `Storage::exists($file->path)` before serving a
     * download — the admin API exposes exactly that as `available`.
     */
    public function proofFile(): ?UploadedFile
    {
        return $this->files()
            ->where('type', 'certificate')
            ->latest()
            ->first();
    }

    public function files()
    {
        return $this->morphMany(UploadedFile::class, 'fileable');
    }
}
