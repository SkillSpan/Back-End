<?php

namespace App\Services\Projects;

use App\Exceptions\ProjectException;
use App\Models\Application;
use App\Models\Project;
use App\Models\ProjectTeam;
use App\Models\ProjectTeamMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Staffing a project: turning accepted applications into a team.
 *
 * WHY THIS IS NOT PART OF ProjectLifecycleService
 * ----------------------------------------------
 * The lifecycle service owns `status`. This one owns `project_teams` /
 * `project_team_members`. Mixing them would mean two reasons for one class to
 * change, and the lifecycle file is already the longest one in the project.
 * The one thing they must share — "who may manage this project" — is called
 * from the lifecycle service rather than copied, so the rule cannot drift.
 *
 * THE RULES
 * ---------
 *   - only accepted applications can be staffed; shortlisting someone does not
 *     put them on the team;
 *   - the application must belong to THIS project (a mismatched pair is a 422,
 *     not a silent no-op);
 *   - nobody is staffed twice;
 *   - the team cannot exceed the project's `capacity` — the same number the
 *     application flow already refuses to overfill;
 *   - staffing can only happen while the project is `open` or `selection`.
 *
 * The first assignment moves an `open` project to `selection` through the
 * lifecycle service, so a project being staffed stops accepting applications
 * in the same request. That transition is audited like every other one.
 */
class ProjectTeamService
{
    public function __construct(
        private readonly ProjectLifecycleService $lifecycle,
    ) {}

    /**
     * Assign accepted applicants to the project's team.
     *
     * @param  array<int, array{application_id: int, project_role?: string|null}>  $members
     */
    public function assign(
        Project $project,
        User $actor,
        array $members,
        ?string $teamName = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ProjectTeam {
        $this->lifecycle->assertMayManageProject($project, $actor);

        if (! in_array($project->status, [Project::STATUS_OPEN, Project::STATUS_SELECTION], true)) {
            throw new ProjectException(
                'Team members can only be assigned while a project is open or in selection.',
                422,
                'PROJECT_NOT_ASSIGNABLE',
                [
                    'project_id' => $project->id,
                    'status' => $project->status,
                    'assignable_statuses' => [Project::STATUS_OPEN, Project::STATUS_SELECTION],
                ],
            );
        }

        $team = DB::transaction(function () use ($project, $members, $teamName): ProjectTeam {
            // One team per project for the Pilot. `project_teams` allows more
            // than one row, but nothing in the product does, and a second team
            // would make "the team" ambiguous everywhere downstream.
            $team = ProjectTeam::firstOrCreate(
                ['project_id' => $project->id],
                ['name' => $teamName],
            );

            if ($teamName !== null && $team->name !== $teamName) {
                $team->name = $teamName;
                $team->save();
            }

            $active = ProjectTeamMember::query()
                ->where('project_team_id', $team->id)
                ->where('assignment_state', 'active')
                ->count();

            foreach ($members as $entry) {
                $applicationId = (int) $entry['application_id'];

                $application = Application::query()->find($applicationId);

                if (! $application) {
                    throw new ProjectException(
                        'One of the selected applications does not exist.',
                        422,
                        'APPLICATION_NOT_FOUND',
                        ['application_id' => $applicationId],
                    );
                }

                if ((int) $application->project_id !== (int) $project->id) {
                    throw new ProjectException(
                        'One of the selected applications does not belong to this project.',
                        422,
                        'APPLICATION_PROJECT_MISMATCH',
                        ['application_id' => $applicationId, 'project_id' => $project->id],
                    );
                }

                if ($application->status !== Application::STATUS_ACCEPTED) {
                    throw new ProjectException(
                        'Only an accepted application can be assigned to the team.',
                        422,
                        'APPLICATION_NOT_ACCEPTED',
                        ['application_id' => $applicationId, 'status' => $application->status],
                    );
                }

                $already = ProjectTeamMember::query()
                    ->where('project_team_id', $team->id)
                    ->where('user_id', $application->applicant_id)
                    ->where('assignment_state', 'active')
                    ->exists();

                if ($already) {
                    throw new ProjectException(
                        'That applicant is already on this project\'s team.',
                        422,
                        'TEAM_MEMBER_ALREADY_ASSIGNED',
                        ['application_id' => $applicationId, 'user_id' => $application->applicant_id],
                    );
                }

                if ($active + 1 > (int) $project->capacity) {
                    throw new ProjectException(
                        'The team cannot be larger than the project\'s capacity.',
                        422,
                        'PROJECT_FULL',
                        [
                            'project_id' => $project->id,
                            'capacity' => (int) $project->capacity,
                            'assigned' => $active,
                        ],
                    );
                }

                ProjectTeamMember::create([
                    'project_team_id' => $team->id,
                    'user_id' => $application->applicant_id,
                    'project_role' => $entry['project_role'] ?? $application->projectRole?->title,
                    'assignment_state' => 'active',
                    'joined_at' => now(),
                ]);

                $active++;
            }

            return $team;
        });

        // Staffing an OPEN project means applications are no longer being
        // taken. Doing it here, through the lifecycle service, keeps the
        // status change audited and the transition guard in one place.
        if ($project->fresh()->status === Project::STATUS_OPEN) {
            $this->lifecycle->beginSelection($project, $actor, $requestId, $ipAddress, $userAgent);
        }

        return $team->fresh(['members.user:id,name,email', 'project:id,title,status']);
    }

    /**
     * The project's team, creating nothing. Used by the start endpoint and by
     * the response of an assignment.
     */
    public function teamFor(Project $project): ?ProjectTeam
    {
        return ProjectTeam::query()
            ->where('project_id', $project->id)
            ->with(['members.user:id,name,email'])
            ->first();
    }
}
