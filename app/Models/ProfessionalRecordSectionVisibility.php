<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProfessionalRecordSectionVisibility extends Model
{
    use HasFactory;

    protected $table = 'professional_record_section_visibility';

    protected $fillable = ['student_profile_id', 'section', 'visibility'];

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class);
    }
}
