<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LearningResource extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'provider', 'url', 'type', 'level', 'language', 'effort_hours', 'cost', 'skill_id', 'prerequisites'];

    protected $casts = ['validated_at' => 'datetime'];

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }

    public function addedBy()
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
