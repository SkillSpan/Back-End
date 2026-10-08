<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectRequiredSkill extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'skill_id', 'minimum_level', 'is_critical_entry'];

    /**
     * `minimum_level` is the stored column behind the API's
     * `minimum_required_level`. Cast to float so a decimal level such as 3.5 or
     * 4.25 round-trips as a number rather than a string — the column is
     * decimal(3,2) and must not be restricted to integers.
     */
    protected $casts = ['is_critical_entry' => 'boolean', 'minimum_level' => 'float'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }
}
