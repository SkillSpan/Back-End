<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProfessionalRecordVerification extends Model
{
    use HasFactory;

    protected $fillable = ['reference', 'record_item_id', 'student_profile_id', 'revoked_at', 'access_count'];

    protected $casts = [
        'revoked_at' => 'datetime',
        'access_count' => 'integer',
    ];

    public function item()
    {
        return $this->belongsTo(ProfessionalRecordItem::class, 'record_item_id');
    }

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
