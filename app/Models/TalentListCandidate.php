<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TalentListCandidate extends Model
{
    use HasFactory;

    protected $fillable = ['talent_list_id', 'student_id', 'added_by', 'note'];

    public function list()
    {
        return $this->belongsTo(TalentList::class, 'talent_list_id');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function addedBy()
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
