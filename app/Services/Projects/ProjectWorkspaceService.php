<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\ProjectTeamMember;
use App\Models\User;

class ProjectWorkspaceService
{
    public function __construct(
        private readonly ProjectWorkspaceAccess $access,
        private readonly MilestoneService $milestones,
    ) {}

    public function payload(Project $project, User $viewer): array
    {
        $this->access->assertMemberOrManager($project, $viewer);

        $membership = $this->access->membership($project, $viewer);
        $milestones = $this->milestones->listForProject($project);
        $team = $this->teamPayload($project, $viewer);

        return [
            'project' => [
                'id' => $project->id,
                'title' => $project->title,
                'type' => $project->type,
                'status' => $project->status,
                'description' => $project->description,
                'objectives' => $project->objectives,
                'learning_outcomes' => $project->learning_outcomes ?? [],
                'difficulty' => $project->difficulty,
                'work_mode' => $project->work_mode,
                'confidentiality' => $project->confidentiality,
                'start_date' => $project->start_date?->toDateString(),
                'end_date' => $project->end_date?->toDateString(),
                'owner' => [
                    'id' => $project->owner_id,
                    'name' => $project->owner?->name,
                ],
                'organization' => [
                    'id' => $project->organization_id,
                    'name' => $project->organization?->name,
                ],
            ],
            'viewer' => [
                'user_id' => $viewer->id,
                'is_member' => $membership !== null,
                'is_manager' => $this->access->mayManage($project, $viewer),
                'project_role' => $membership?->project_role,
                'can_submit' => $membership !== null
                    && in_array($project->status, [Project::STATUS_ACTIVE, Project::STATUS_UNDER_REVIEW], true),
            ],
            'milestones' => $this->milestones->payloadCollection($milestones),
            'team' => $team,
            'communication_references' => [],
            'guidance' => [
                'available' => true,
                'endpoint' => '/api/v1/assistant/ask',
                'scope' => 'bounded_guidance_only',
            ],
        ];
    }

    private function teamPayload(Project $project, User $viewer): array
    {
        $team = $project->currentTeam();

        if ($team === null) {
            return [];
        }

        return $team->members
            ->where('assignment_state', 'active')
            ->values()
            ->map(fn (ProjectTeamMember $member) => [
                'user_id' => $member->user_id,
                'name' => $member->user?->name,
                'role' => $member->project_role,
                'is_self' => (int) $member->user_id === (int) $viewer->id,
                'contribution' => null,
            ])->all();
    }
}
