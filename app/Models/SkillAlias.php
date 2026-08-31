<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SkillAlias extends Model
{
    use HasFactory;

    protected $fillable = ['skill_id', 'alias'];

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }
}
