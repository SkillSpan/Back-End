<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's internal note about a candidate.
 *
 * Visible to the whole organization (that is the point of a shared note) and
 * never to the student. `author_id` is attribution for the UI.
 */
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
