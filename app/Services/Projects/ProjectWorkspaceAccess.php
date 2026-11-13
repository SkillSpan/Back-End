<?php

namespace App\Services\Projects;

use App\Exceptions\ProjectException;
use App\Models\Project;
use App\Models\ProjectTeamMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProjectWorkspaceAccess
{
    public function __construct(private readonly ProjectLifecycleService $lifecycle) {}

    public function membership(Project $project, User $user): ?ProjectTeamMember
    {
        return ProjectTeamMember::query()
            ->where('user_id', $user->id)
            ->where('assignment_state', 'active')
            ->whereIn('project_team_id', $project->teams()->select('id'))
            ->first();
    }

    public function isMember(Project $project, User $user): bool
    {
        return $this->membership($project, $user) !== null;
    }

    public function mayManage(Project $project, User $user): bool
    {
        if ($this->lifecycle->mayManageProject($project, $user)) {
            return true;
        }

        if ($project->organization_id === null) {
            return false;
        }

        return DB::table('organization_members')
            ->where('user_id', $user->id)
            ->where('organization_id', $project->organization_id)
            ->where('status', 'active')
            ->exists();
    }

    public function assertMember(Project $project, User $user): ProjectTeamMember
    {
        $membership = $this->membership($project, $user);

        if ($membership === null) {
            throw new ProjectException(
                'Only accepted project participants can access this project workspace.',
                403,
                'PROJECT_WORKSPACE_FORBIDDEN',
                ['project_id' => $project->id],
            );
        }

        return $membership;
    }

    public function assertMemberOrManager(Project $project, User $user): void
    {
        if ($this->isMember($project, $user) || $this->mayManage($project, $user)) {
            return;
        }

        throw new ProjectException(
            'Only project participants and authorized staff can access this project workspace.',
            403,
            'PROJECT_WORKSPACE_FORBIDDEN',
            ['project_id' => $project->id],
        );
    }

    public function assertManager(Project $project, User $user): void
    {
        if ($this->mayManage($project, $user)) {
            return;
        }

        throw new ProjectException(
            'Only an authorized representative of the owning organization may manage this project.',
            403,
            'PROJECT_NOT_OWNED',
            ['project_id' => $project->id],
        );
    }

    public function memberIds(Project $project): array
    {
        return ProjectTeamMember::query()
            ->where('assignment_state', 'active')
            ->whereIn('project_team_id', $project->teams()->select('id'))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
