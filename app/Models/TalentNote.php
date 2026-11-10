<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TalentNote extends Model
{
    use HasFactory;

    protected $fillable = ['organization_id', 'student_id', 'author_id', 'body'];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
