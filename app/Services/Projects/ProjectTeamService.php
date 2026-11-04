<?php

namespace App\Services\Projects;

use App\Exceptions\ProjectException;
use App\Models\Application;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\ProjectTeam;
use App\Models\ProjectTeamMember;
use App\Models\TeamInvitation;
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
                    'application_id' => $application->id,
                    'project_role' => $this->roleTitle($project, $entry) ?? $application->projectRole?->title,
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
     * The title to store for one assignment.
     *
     * A caller may name the role (`project_role`) or point at it
     * (`project_role_id`); an id is resolved against THIS project's roles, so a
     * role belonging to another project cannot be smuggled in. Returns null
     * when neither was sent, and the caller falls back to the role on the
     * application.
     *
     * @param  array<string, mixed>  $entry
     */
    private function roleTitle(Project $project, array $entry): ?string
    {
        if (isset($entry['project_role']) && $entry['project_role'] !== '') {
            return (string) $entry['project_role'];
        }

        if (empty($entry['project_role_id'])) {
            return null;
        }

        $role = ProjectRole::query()
            ->where('project_id', $project->id)
            ->whereKey((int) $entry['project_role_id'])
            ->first();

        if (! $role) {
            throw new ProjectException(
                'One of the selected roles does not belong to this project.',
                422,
                'PROJECT_ROLE_NOT_FOUND',
                ['project_role_id' => (int) $entry['project_role_id'], 'project_id' => $project->id],
            );
        }

        return $role->title;
    }

    /**
     * Reading the team is wider than changing it: the people on the team may
     * see it too, otherwise a participant cannot open the project they were
     * staffed into. Nobody else.
     */
    public function assertMayView(Project $project, User $actor): void
    {
        if ($this->lifecycle->mayManageProject($project, $actor)) {
            return;
        }

        $isMember = ProjectTeamMember::query()
            ->whereHas('team', fn ($query) => $query->where('project_id', $project->id))
            ->where('user_id', $actor->id)
            ->where('assignment_state', 'active')
            ->exists();

        if ($isMember) {
            return;
        }

        throw new ProjectException(
            'You are not authorized to view this project\'s team.',
            403,
            'PROJECT_TEAM_FORBIDDEN',
            ['project_id' => $project->id],
        );
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

    // -----------------------------------------------------------------
    // The team screen
    // -----------------------------------------------------------------

    /**
     * Everything the team screen reads, in one answer.
     *
     * WHY IT IS BUILT FROM ACCEPTED APPLICATIONS, NOT FROM THE TEAM ROWS
     * -----------------------------------------------------------------
     * A company works through applications and then staffs them. If this
     * payload only listed team rows, a participant the company accepted but has
     * not assigned yet would vanish from the screen — and that is precisely the
     * person the screen exists to place. So the spine is "accepted
     * applications", and the assignment is attached to each one when it exists.
     *
     * `roles` is the composition view: how many people each role still needs.
     * `project_roles` carries no capacity of its own (capacity is
     * `projects.capacity`), so the number that matters here is demand — how many
     * accepted applications asked for that role — against how many are actually
     * assigned.
     *
     * @return array<string, mixed>
     */
    public function payload(Project $project): array
    {
        $applications = Application::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [Application::STATUS_ACCEPTED, Application::STATUS_WAITLISTED])
            ->with(['applicant:id,name,email', 'projectRole:id,title'])
            ->orderBy('id')
            ->get();

        $team = $this->teamFor($project);

        $members = $team?->members->where('assignment_state', 'active') ?? collect();

        $byApplication = $members->keyBy('application_id');

        $accepted = $applications->where('status', Application::STATUS_ACCEPTED);

        $participants = $accepted->map(function (Application $application) use ($byApplication): array {
            $member = $byApplication->get($application->id);
            $invitation = $member?->latestInvitation;

            return [
                'application_id' => $application->id,
                'user_id' => $application->applicant_id,
                'name' => $application->applicant?->name,
                'email' => $application->applicant?->email,
                'requested_role' => $application->projectRole?->title,
                'assigned' => $member !== null,
                'declined_invitation' => $invitation?->status === TeamInvitation::STATUS_DECLINED,
                'assignment' => $member === null ? null : [
                    'id' => $member->id,
                    'role' => $member->project_role,
                    'status' => $member->assignment_state,
                    'joined_at' => $member->joined_at?->toIso8601String(),
                    'invitation' => $invitation === null ? null : [
                        'id' => $invitation->id,
                        'status' => $invitation->status,
                        'sent_at' => $invitation->sent_at?->toIso8601String(),
                        'responded_at' => $invitation->responded_at?->toIso8601String(),
                    ],
                ],
            ];
        })->values()->all();

        // Demand per role, from the accepted applications, and what is staffed.
        $demand = $accepted->groupBy(fn (Application $a) => (string) ($a->projectRole?->title ?? ''))
            ->map(fn ($group) => $group->count());

        $staffed = $members->groupBy(fn (ProjectTeamMember $m) => (string) ($m->project_role ?? ''))
            ->map(fn ($group) => $group->count());

        $roleTitles = $project->projectRoles()->orderBy('id')->pluck('title')->all();

        // A role that accepted applicants asked for, or that someone is
        // assigned to, is part of the composition even if it was later
        // deactivated — otherwise the numbers would not add up on screen.
        $allTitles = array_values(array_unique(array_filter(array_merge(
            $roleTitles,
            $demand->keys()->all(),
            $staffed->keys()->all(),
        ))));

        $roles = [];
        $unfilled = [];

        foreach ($allTitles as $title) {
            $capacity = (int) ($demand[$title] ?? 0);
            $assigned = (int) ($staffed[$title] ?? 0);

            $roles[] = [
                'id' => $project->projectRoles->firstWhere('title', $title)?->id,
                'name' => $title,
                'capacity' => $capacity,
                'assigned' => $assigned,
                'confirmed' => $members->where('project_role', $title)
                    ->filter(fn (ProjectTeamMember $m) => $m->latestInvitation?->status === TeamInvitation::STATUS_ACCEPTED)
                    ->count(),
                'remaining' => max(0, $capacity - $assigned),
            ];

            if ($capacity > $assigned) {
                $unfilled[] = ['name' => $title, 'needed' => $capacity - $assigned];
            }
        }

        return [
            'project' => [
                'id' => $project->id,
                'title' => $project->title,
                'status' => $project->status,
                'capacity' => (int) $project->capacity,
                'start_date' => $project->start_date?->toDateString(),
            ],
            'team' => $team === null ? null : [
                'id' => $team->id,
                'name' => $team->name,
                'members_count' => $members->count(),
            ],
            'roles' => $roles,
            'participants' => $participants,
            'waitlist' => $applications
                ->where('status', Application::STATUS_WAITLISTED)
                ->map(fn (Application $a) => [
                    'application_id' => $a->id,
                    'name' => $a->applicant?->name,
                    'requested_role' => $a->projectRole?->title,
                ])->values()->all(),
            'composition' => [
                'valid' => $unfilled === [],
                'unfilled_roles' => $unfilled,
            ],
        ];
    }

    /**
     * Take one participant off the team.
     *
     * The row is marked `removed` rather than deleted: the assignment is part of
     * the project's history, and a submission that referenced it must not lose
     * its author. Capacity is therefore freed by the state, not by the absence
     * of a row.
     */
    public function unassign(
        Project $project,
        User $actor,
        int $applicationId,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ProjectTeam {
        $this->lifecycle->assertMayManageProject($project, $actor);

        $team = $this->teamFor($project);

        if ($team === null) {
            throw new ProjectException(
                'This project has no team yet.',
                404,
                'PROJECT_TEAM_NOT_FOUND',
                ['project_id' => $project->id],
            );
        }

        $member = $team->members
            ->first(fn (ProjectTeamMember $m) => (int) $m->application_id === $applicationId
                && $m->assignment_state === 'active');

        if ($member === null) {
            throw new ProjectException(
                'That application is not assigned to this project\'s team.',
                404,
                'TEAM_MEMBER_NOT_FOUND',
                ['project_id' => $project->id, 'application_id' => $applicationId],
            );
        }

        if (in_array($project->status, [Project::STATUS_COMPLETED, Project::STATUS_ARCHIVED], true)) {
            throw new ProjectException(
                'A finished project\'s team can no longer change.',
                422,
                'PROJECT_TEAM_LOCKED',
                ['project_id' => $project->id, 'status' => $project->status],
            );
        }

        $member->assignment_state = 'removed';
        $member->left_at = now();
        $member->save();

        // A pending invitation to someone who is no longer on the team would be
        // a dead link in the learner's inbox.
        TeamInvitation::query()
            ->where('project_team_member_id', $member->id)
            ->where('status', TeamInvitation::STATUS_PENDING)
            ->update([
                'status' => TeamInvitation::STATUS_CANCELLED,
                'responded_at' => now(),
            ]);

        $this->lifecycle->auditTeamChange($project, $actor, 'team.member.removed', [
            'application_id' => $applicationId,
            'user_id' => $member->user_id,
        ], $requestId, $ipAddress, $userAgent);

        return $team->fresh(['members.user:id,name,email']);
    }

    // -----------------------------------------------------------------
    // Invitations
    // -----------------------------------------------------------------

    /**
     * Invite every assigned member who has not been invited yet (or whose
     * invitation was declined, so a re-send is possible).
     *
     * Idempotent by design: pressing the button twice sends nothing the second
     * time and answers `sent: 0` rather than creating duplicates. The company's
     * intent is "these people should have an invitation", not "send N emails".
     *
     * @return array{sent: int, invitations: array<int, array<string, mixed>>}
     */
    public function invite(
        Project $project,
        User $actor,
        ?string $message = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $this->lifecycle->assertMayManageProject($project, $actor);

        $team = $this->teamFor($project);

        // No team row and an empty team are the same answer to the caller:
        // there is nobody to invite. Reporting "team not found" for a project
        // that plainly exists would send the client looking for the wrong bug.
        $members = $team === null
            ? collect()
            : $team->members->where('assignment_state', 'active');

        if ($members->isEmpty()) {
            throw new ProjectException(
                'There is nobody on the team to invite.',
                422,
                'PROJECT_TEAM_EMPTY',
                ['project_id' => $project->id],
            );
        }

        $created = [];

        foreach ($members as $member) {
            $pending = TeamInvitation::query()
                ->where('project_team_member_id', $member->id)
                ->where('status', TeamInvitation::STATUS_PENDING)
                ->exists();

            if ($pending) {
                continue;
            }

            $created[] = TeamInvitation::create([
                'project_team_member_id' => $member->id,
                'project_id' => $project->id,
                'user_id' => $member->user_id,
                'project_role' => $member->project_role,
                'message' => $message,
                'status' => TeamInvitation::STATUS_PENDING,
                'sent_at' => now(),
            ]);
        }

        if ($created !== []) {
            $this->lifecycle->auditTeamChange($project, $actor, 'team.invitations.sent', [
                'count' => count($created),
                'invitation_ids' => array_map(fn (TeamInvitation $i) => $i->id, $created),
            ], $requestId, $ipAddress, $userAgent);
        }

        return [
            'sent' => count($created),
            'invitations' => array_map(fn (TeamInvitation $i) => [
                'id' => $i->id,
                'user_id' => $i->user_id,
                'project_role' => $i->project_role,
                'status' => $i->status,
                'sent_at' => $i->sent_at?->toIso8601String(),
            ], $created),
        ];
    }

    /**
     * One invitation, for the person invited or for whoever manages the project.
     */
    public function invitationFor(TeamInvitation $invitation, User $actor): TeamInvitation
    {
        $isInvitee = (int) $invitation->user_id === (int) $actor->id;

        if (! $isInvitee && ! $actor->hasRole('admin')) {
            $project = $invitation->project;

            if ($project === null || ! $this->lifecycle->mayManageProject($project, $actor)) {
                throw new ProjectException(
                    'You are not authorized to view this invitation.',
                    403,
                    'INVITATION_VIEW_FORBIDDEN',
                    ['invitation_id' => $invitation->id],
                );
            }
        }

        return $invitation->load(['project:id,title,status,start_date', 'project.organization:id,name']);
    }

    /**
     * The learner's answer. Only the invited person may answer, and only once:
     * a second answer is a 422 rather than a silent overwrite, because the
     * company has already seen the first one.
     */
    public function respond(
        TeamInvitation $invitation,
        User $actor,
        string $response,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): TeamInvitation {
        if ((int) $invitation->user_id !== (int) $actor->id) {
            throw new ProjectException(
                'Only the invited person can answer this invitation.',
                403,
                'INVITATION_RESPONSE_FORBIDDEN',
                ['invitation_id' => $invitation->id],
            );
        }

        if (! in_array($response, TeamInvitation::RESPONSES, true)) {
            throw new ProjectException(
                'The response must be accepted or declined.',
                422,
                'INVITATION_INVALID_RESPONSE',
                ['response' => $response, 'allowed' => TeamInvitation::RESPONSES],
            );
        }

        if (! $invitation->isPending()) {
            throw new ProjectException(
                'This invitation has already been answered.',
                422,
                'INVITATION_ALREADY_ANSWERED',
                ['invitation_id' => $invitation->id, 'status' => $invitation->status],
            );
        }

        $invitation->status = $response;
        $invitation->responded_at = now();
        $invitation->save();

        $this->lifecycle->auditTeamChange($invitation->project, $actor, 'team.invitation.answered', [
            'invitation_id' => $invitation->id,
            'response' => $response,
        ], $requestId, $ipAddress, $userAgent);

        return $invitation->fresh(['project:id,title,status']);
    }
}
