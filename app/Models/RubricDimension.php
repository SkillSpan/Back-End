<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RubricDimension extends Model
{
    use HasFactory;

    protected $fillable = ['rubric_id', 'name', 'weight', 'skill_id'];

    public function rubric()
    {
        return $this->belongsTo(Rubric::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }
}
