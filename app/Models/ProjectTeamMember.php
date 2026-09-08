<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectTeamMember extends Model
{
    use HasFactory;

    protected $fillable = ['project_team_id', 'user_id', 'project_role'];

    protected $casts = ['joined_at' => 'datetime', 'left_at' => 'datetime'];

    public function team()
    {
        return $this->belongsTo(ProjectTeam::class, 'project_team_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class, 'contributor_id', 'user_id');
    }
}
