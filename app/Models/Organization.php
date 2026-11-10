<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'type', 'contact_email', 'contact_phone', 'website', 'description', 'industry', 'company_size', 'country', 'city', 'address', 'postal_code'];

    protected $casts = [
        'verified_at' => 'datetime',
        'profile_updated_at' => 'datetime',
        'reverification_required_at' => 'datetime',
        'profile_completeness' => 'integer',
    ];

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
