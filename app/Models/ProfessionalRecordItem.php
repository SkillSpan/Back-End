<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProfessionalRecordItem extends Model
{
    use HasFactory;

    protected $fillable = ['student_profile_id', 'source_type', 'source_id', 'title', 'description', 'visibility', 'achieved_at'];

    protected $casts = ['achieved_at' => 'date'];

    public function studentProfile()
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function source()
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }
}
