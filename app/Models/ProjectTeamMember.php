<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectTeamMember extends Model
{
    use HasFactory;

    protected $fillable = ['project_team_id', 'user_id', 'application_id', 'project_role', 'assignment_state'];

    protected $casts = ['joined_at' => 'datetime', 'left_at' => 'datetime'];

    public function team()
    {
        return $this->belongsTo(ProjectTeam::class, 'project_team_id');
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function invitations()
    {
        return $this->hasMany(TeamInvitation::class);
    }

    public function latestInvitation()
    {
        return $this->hasOne(TeamInvitation::class)->latestOfMany();
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
