<?php

namespace App\Services\Projects;

use App\Exceptions\ProjectException;
use App\Models\Application;
use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectEligibilityConstraint;
use App\Models\ProjectRequiredSkill;
use App\Models\ProjectRole;
use App\Models\ProjectTeamMember;
use App\Models\User;
use App\Traits\AuditsActions;
use Illuminate\Support\Facades\DB;

class ProjectLifecycleService
{
    use AuditsActions;

    private const MANAGEMENT_ROLES = ['company_admin', 'university_admin'];

    private const TYPE_COMPANY_SPONSORED = 'company_sponsored';

    private const TYPE_SIMULATION = 'simulation';

    private const WRITABLE_ATTRIBUTES = [
        'type', 'domain', 'title', 'description', 'objectives', 'learning_outcomes',
        'difficulty', 'work_mode', 'career_role_id', 'schedule', 'capacity', 'min_team_size',
        'start_date', 'end_date', 'application_deadline', 'confidentiality',
    ];

    private array $careerRoleTitles = [];

    public function create(
        User $actor,
        array $data,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $this->assertMayCreate($actor);

        $ownership = $this->resolveOwnership(
            $actor,
            (string) $data['type'],
            $data['organization_id'] ?? null,
            $data['owner_id'] ?? null,
        );

        $project = DB::transaction(function () use ($data, $ownership): Project {
            $project = new Project($this->writableAttributes($data));
            $project->owner_id = $ownership['owner_id'];
            $project->organization_id = $ownership['organization_id'];
            $project->status = Project::STATUS_DRAFT;
            $project->version = 1;

            $this->applyCareerRoleProjection($project, $data['career_role_id'] ?? null);

            $project->save();

            $this->syncRequiredSkills($project, $data['required_skills'] ?? []);
            $this->syncRoles($project, $data['roles'] ?? []);
            $this->syncEligibilityConstraints($project, $data['eligibility_constraints'] ?? []);

            $this->assertRequiredSkillsBelongToCareerRole($project);

            $this->assertDateOrdering($project);

            return $project;
        });

        $this->audit(
            $actor->id,
            'project.created',
            'project',
            $project->id,
            null,
            [
                'status' => $project->status,
                'type' => $project->type,
                'organization_id' => $project->organization_id,
                'version' => $project->version,
            ],
            'project_lifecycle',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $this->forResponse($project);
    }

    public function update(
        Project $project,
        User $actor,
        array $data,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $this->assertMayManage($project, $actor);
        $this->assertEditable($project);

        $ownership = null;

        if (array_key_exists('type', $data) && (string) $data['type'] !== (string) $project->type) {
            $ownership = $this->resolveOwnership(
                $actor,
                (string) $data['type'],
                $data['organization_id'] ?? null,
                $data['owner_id'] ?? null,
                (int) $project->owner_id,
            );
        }

        $before = $project->only(array_merge(self::WRITABLE_ATTRIBUTES, ['version']));
        $versionedBefore = $project->only(Project::VERSIONED_FIELDS);

        DB::transaction(function () use ($project, $data, $ownership): void {
            $project->fill($this->writableAttributes($data));

            if ($ownership !== null) {
                $project->organization_id = $ownership['organization_id'];
                $project->owner_id = $ownership['owner_id'];
            }

            if (array_key_exists('career_role_id', $data) && $data['career_role_id'] !== null) {
                $this->applyCareerRoleProjection($project, $data['career_role_id']);
            }

            $project->save();

            if (array_key_exists('required_skills', $data)) {
                $this->syncRequiredSkills($project, $data['required_skills']);
            }

            if (array_key_exists('roles', $data)) {
                $this->syncRoles($project, $data['roles']);
            }

            if (array_key_exists('eligibility_constraints', $data)) {
                $this->syncEligibilityConstraints($project, $data['eligibility_constraints']);
            }

            $this->assertRequiredSkillsBelongToCareerRole($project);

            $this->assertDateOrdering($project);
        });

        $versionedAfter = $project->only(Project::VERSIONED_FIELDS);
        $material = $versionedBefore != $versionedAfter
            || array_key_exists('required_skills', $data);

        if ($material) {
            $project->version = (int) $project->version + 1;
            $project->save();
        }

        $this->audit(
            $actor->id,
            'project.updated',
            'project',
            $project->id,
            $before,
            [
                'version' => $project->version,
                'version_bumped' => $material,
            ],
            'project_lifecycle',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $this->forResponse($project);
    }

    public function clone(
        Project $source,
        User $actor,
        array $data = [],
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $this->assertMayManage($source, $actor);

        $copy = DB::transaction(function () use ($source, $data): Project {
            $project = new Project($source->only(self::WRITABLE_ATTRIBUTES));

            $project->title = $this->clonedTitle($source, $data['title'] ?? null);

            foreach (['capacity', 'start_date', 'end_date', 'application_deadline'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== null) {
                    $project->{$field} = $data[$field];
                }
            }

            $project->owner_id = $source->owner_id;
            $project->organization_id = $source->organization_id;
            $project->status = Project::STATUS_DRAFT;
            $project->version = 1;

            $project->save();

            $this->syncRequiredSkills($project, $source->requiredSkills
                ->map(fn (ProjectRequiredSkill $skill) => [
                    'skill_id' => $skill->skill_id,
                    'minimum_required_level' => $skill->minimum_level,
                    'is_critical_entry' => (bool) $skill->is_critical_entry,
                ])
                ->all());

            $this->syncRoles($project, $source->projectRoles
                ->map(fn (ProjectRole $role) => [
                    'title' => $role->title,
                    'description' => $role->description,
                    'is_active' => (bool) $role->is_active,
                ])
                ->all());

            $this->syncEligibilityConstraints($project, $source->eligibilityConstraints
                ->map(fn (ProjectEligibilityConstraint $constraint) => [
                    'constraint_type' => $constraint->constraint_type,
                    'value' => $constraint->value,
                ])
                ->all());

            $this->assertRequiredSkillsBelongToCareerRole($project);
            $this->assertDateOrdering($project);

            return $project;
        });

        $this->audit(
            $actor->id,
            'project.cloned',
            'project',
            $copy->id,
            ['source_project_id' => $source->id],
            [
                'status' => $copy->status,
                'type' => $copy->type,
                'organization_id' => $copy->organization_id,
                'version' => $copy->version,
            ],
            'project_lifecycle',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $this->forResponse($copy);
    }

    private function clonedTitle(Project $source, mixed $requested): string
    {
        $title = is_string($requested) ? trim($requested) : '';

        if ($title === '') {
            $title = trim((string) $source->title).' (copy)';
        }

        return mb_substr($title, 0, 255);
    }

    public function submit(
        Project $project,
        User $actor,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $this->assertMayManage($project, $actor);
        $this->assertTransition($project, Project::STATUS_SUBMITTED);
        $this->assertComplete($project, 'submitted');

        return $this->transition($project, $actor, Project::STATUS_SUBMITTED, null, 'project.submitted', [], $requestId, $ipAddress, $userAgent);
    }

    public function approve(
        Project $project,
        User $actor,
        ?string $reason = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $this->assertMayReview($project, $actor);
        $this->assertTransition($project, Project::STATUS_APPROVED);
        $this->assertComplete($project, 'approved');

        return $this->transition(
            $project,
            $actor,
            Project::STATUS_APPROVED,
            $reason,
            'project.approved',
            ['approved_by' => $actor->id, 'approved_at' => now()],
            $requestId,
            $ipAddress,
            $userAgent,
        );
    }

    public function requestChanges(
        Project $project,
        User $actor,
        string $reason,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $this->assertMayReview($project, $actor);
        $this->assertTransition($project, Project::STATUS_CHANGES_REQUESTED);

        if (trim($reason) === '') {
            throw new ProjectException(
                'A reason is required when requesting changes.',
                422,
                'PROJECT_REASON_REQUIRED',
                ['project_id' => $project->id],
            );
        }

        return $this->transition($project, $actor, Project::STATUS_CHANGES_REQUESTED, $reason, 'project.changes_requested', [], $requestId, $ipAddress, $userAgent);
    }

    public function reject(
        Project $project,
        User $actor,
        ?string $reason = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $this->assertMayReview($project, $actor);
        $this->assertTransition($project, Project::STATUS_REJECTED);

        return $this->transition($project, $actor, Project::STATUS_REJECTED, $reason, 'project.rejected', [], $requestId, $ipAddress, $userAgent);
    }

    public function open(
        Project $project,
        User $actor,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $this->assertMayManage($project, $actor);
        $this->assertTransition($project, Project::STATUS_OPEN);

        $this->assertComplete($project, 'opened');

        return $this->transition($project, $actor, Project::STATUS_OPEN, null, 'project.opened', [], $requestId, $ipAddress, $userAgent);
    }

    public function beginSelection(
        Project $project,
        User $actor,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $this->assertMayManage($project, $actor);
        $this->assertTransition($project, Project::STATUS_SELECTION);

        return $this->transition($project, $actor, Project::STATUS_SELECTION, null, 'project.selection_started', [], $requestId, $ipAddress, $userAgent);
    }

    public function start(
        Project $project,
        User $actor,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $this->assertMayManage($project, $actor);
        $this->assertTransition($project, Project::STATUS_ACTIVE);
        $this->assertTeamAssigned($project);

        return $this->transition($project, $actor, Project::STATUS_ACTIVE, null, 'project.started', [], $requestId, $ipAddress, $userAgent);
    }

    public function reopen(
        Project $project,
        User $actor,
        ?string $reason = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $this->assertMayManage($project, $actor);
        $this->assertTransition($project, Project::STATUS_OPEN);

        return $this->transition($project, $actor, Project::STATUS_OPEN, $reason, 'project.reopened', [], $requestId, $ipAddress, $userAgent);
    }

    public function revise(
        Project $project,
        User $actor,
        ?string $reason = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $this->assertMayManage($project, $actor);
        $this->assertTransition($project, Project::STATUS_DRAFT);

        return $this->transition(
            $project,
            $actor,
            Project::STATUS_DRAFT,
            $reason,
            'project.revised',
            ['version' => ((int) $project->version) + 1],
            $requestId,
            $ipAddress,
            $userAgent,
        );
    }

    public function complete(
        Project $project,
        User $actor,
        ?string $reason = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $this->assertMayManage($project, $actor);
        $this->assertTransition($project, Project::STATUS_COMPLETED);

        return $this->transition($project, $actor, Project::STATUS_COMPLETED, $reason, 'project.completed', [], $requestId, $ipAddress, $userAgent);
    }

    public function cancelByOwner(
        Project $project,
        User $actor,
        ?string $reason = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $this->assertMayManage($project, $actor);
        $this->assertTransition($project, Project::STATUS_CANCELLED);

        if (! in_array($project->status, self::CANCELLABLE_STATUSES, true)) {
            throw new ProjectException(
                'A project can only be cancelled while it is open, in selection, active or under review.',
                422,
                'PROJECT_NOT_CANCELLABLE',
                [
                    'project_id' => $project->id,
                    'status' => $project->status,
                    'cancellable_statuses' => self::CANCELLABLE_STATUSES,
                ],
            );
        }

        $pending = Application::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [Application::STATUS_SUBMITTED, Application::STATUS_SHORTLISTED, Application::STATUS_WAITLISTED]);

        $affected = (clone $pending)->count();

        return DB::transaction(function () use ($project, $actor, $reason, $requestId, $ipAddress, $userAgent, $pending, $affected) {
            $cancelled = $this->transition(
                $project,
                $actor,
                Project::STATUS_CANCELLED,
                $reason,
                'project.cancelled',
                [],
                $requestId,
                $ipAddress,
                $userAgent,
                ['cancelled_applications' => $affected],
            );

            $pending->update(['status' => Application::STATUS_WITHDRAWN]);

            return $cancelled;
        });
    }

    public function archive(
        Project $project,
        User $actor,
        ?string $reason = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $this->assertMayManage($project, $actor);
        $this->assertTransition($project, Project::STATUS_ARCHIVED);

        return $this->transition($project, $actor, Project::STATUS_ARCHIVED, $reason, 'project.archived', [], $requestId, $ipAddress, $userAgent);
    }

    public function assertMayManageProject(Project $project, User $actor): void
    {
        $this->assertMayManage($project, $actor);
    }

    public function mayManageProject(Project $project, User $actor): bool
    {
        return $this->isPlatformAdmin($actor)
            || (int) $project->owner_id === (int) $actor->id;
    }

    public function auditTeamChange(
        ?Project $project,
        User $actor,
        string $action,
        array $after = [],
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        $this->audit(
            $actor->id,
            $action,
            'project',
            $project?->id,
            null,
            $after,
            'project_team',
            $requestId,
            $ipAddress,
            $userAgent,
        );
    }

    private function assertTeamAssigned(Project $project): void
    {
        $members = ProjectTeamMember::query()
            ->whereHas('team', fn ($query) => $query->where('project_id', $project->id))
            ->where('assignment_state', 'active')
            ->count();

        if ($members > 0) {
            return;
        }

        throw new ProjectException(
            'A project cannot start before its team is assigned.',
            422,
            'PROJECT_TEAM_EMPTY',
            ['project_id' => $project->id],
        );
    }

    private const CANCELLABLE_STATUSES = [
        Project::STATUS_OPEN,
        Project::STATUS_SELECTION,
        Project::STATUS_ACTIVE,
        Project::STATUS_UNDER_REVIEW,
    ];

    private const EXECUTABLE_TRANSITIONS = [
        Project::STATUS_DRAFT => [
            Project::STATUS_SUBMITTED => 'submit',
        ],
        Project::STATUS_SUBMITTED => [
            Project::STATUS_APPROVED => 'approve',
            Project::STATUS_CHANGES_REQUESTED => 'requestChanges',
            Project::STATUS_REJECTED => 'reject',
        ],
        Project::STATUS_CHANGES_REQUESTED => [
            Project::STATUS_SUBMITTED => 'submit',
        ],
        Project::STATUS_APPROVED => [
            Project::STATUS_OPEN => 'open',
        ],
        Project::STATUS_OPEN => [
            Project::STATUS_SELECTION => 'beginSelection',
            Project::STATUS_CANCELLED => 'cancel',
        ],
        Project::STATUS_SELECTION => [
            Project::STATUS_ACTIVE => 'start',
            Project::STATUS_OPEN => 'reopen',
            Project::STATUS_CANCELLED => 'cancel',
        ],
        Project::STATUS_ACTIVE => [
            Project::STATUS_COMPLETED => 'complete',
            Project::STATUS_CANCELLED => 'cancel',
        ],
        Project::STATUS_UNDER_REVIEW => [
            Project::STATUS_COMPLETED => 'complete',
            Project::STATUS_CANCELLED => 'cancel',
        ],
        Project::STATUS_REJECTED => [
            Project::STATUS_DRAFT => 'revise',
            Project::STATUS_ARCHIVED => 'archive',
        ],
        Project::STATUS_COMPLETED => [
            Project::STATUS_ARCHIVED => 'archive',
        ],
        Project::STATUS_CANCELLED => [
            Project::STATUS_ARCHIVED => 'archive',
        ],
    ];

    public function transitionTo(
        Project $project,
        User $actor,
        string $to,
        ?string $reason = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $method = self::EXECUTABLE_TRANSITIONS[$project->status][$to] ?? null;

        if ($method === null) {
            throw $this->invalidTransition(
                $project,
                $to,
                array_keys(self::EXECUTABLE_TRANSITIONS[$project->status] ?? []),
            );
        }

        return match ($method) {
            'submit' => $this->submit($project, $actor, $requestId, $ipAddress, $userAgent),
            'approve' => $this->approve($project, $actor, $reason, $requestId, $ipAddress, $userAgent),
            'requestChanges' => $this->requestChanges($project, $actor, (string) $reason, $requestId, $ipAddress, $userAgent),
            'reject' => $this->reject($project, $actor, $reason, $requestId, $ipAddress, $userAgent),
            'open' => $this->open($project, $actor, $requestId, $ipAddress, $userAgent),
            'beginSelection' => $this->beginSelection($project, $actor, $requestId, $ipAddress, $userAgent),
            'start' => $this->start($project, $actor, $requestId, $ipAddress, $userAgent),
            'reopen' => $this->reopen($project, $actor, $reason, $requestId, $ipAddress, $userAgent),
            'revise' => $this->revise($project, $actor, $reason, $requestId, $ipAddress, $userAgent),
            'complete' => $this->complete($project, $actor, $reason, $requestId, $ipAddress, $userAgent),
            'cancel' => $this->cancelByOwner($project, $actor, $reason, $requestId, $ipAddress, $userAgent),
            'archive' => $this->archive($project, $actor, $reason, $requestId, $ipAddress, $userAgent),
        };
    }

    public function executableTransitions(Project $project): array
    {
        return array_keys(self::EXECUTABLE_TRANSITIONS[$project->status] ?? []);
    }

    public function cancel(
        Project $project,
        User $actor,
        ?string $reason = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        if (! $this->isPlatformAdmin($actor)) {
            throw new ProjectException(
                'Only a platform administrator may cancel a project.',
                403,
                'PROJECT_CANCEL_FORBIDDEN',
                ['project_id' => $project->id],
            );
        }

        if (in_array($project->status, [
            Project::STATUS_CANCELLED,
            Project::STATUS_ARCHIVED,
            Project::STATUS_REJECTED,
        ], true)) {
            throw new ProjectException(
                'This project has already reached a final state and cannot be cancelled.',
                422,
                'PROJECT_NOT_CANCELLABLE',
                [
                    'project_id' => $project->id,
                    'status' => $project->status,
                ],
            );
        }

        return $this->transition(
            $project,
            $actor,
            Project::STATUS_CANCELLED,
            $reason,
            'project.cancelled',
            [],
            $requestId,
            $ipAddress,
            $userAgent,
        );
    }

    private function transition(
        Project $project,
        User $actor,
        string $to,
        ?string $reason,
        string $action,
        array $extra = [],
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        array $auditExtra = [],
    ): Project {
        $from = $project->status;

        $claimed = Project::whereKey($project->getKey())
            ->where('status', $from)
            ->update(array_merge(['status' => $to], $extra));

        if ($claimed === 0) {
            $project->refresh();

            throw new ProjectException(
                'The project status changed while this request was being processed.',
                409,
                'PROJECT_STATUS_CONFLICT',
                [
                    'project_id' => $project->id,
                    'status' => $project->status,
                    'requested_status' => $to,
                ],
            );
        }

        $project->refresh();

        $this->audit(
            $actor->id,
            $action,
            'project',
            $project->id,
            ['status' => $from],
            array_merge(
                [
                    'status' => $to,
                    'reason' => $reason,
                ],
                $extra,
                $auditExtra,
            ),
            'project_lifecycle',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $this->forResponse($project);
    }

    private function assertMayCreate(User $actor): void
    {
        if ($this->isPlatformAdmin($actor)) {
            return;
        }

        foreach (self::MANAGEMENT_ROLES as $role) {
            if ($actor->hasRole($role)) {
                return;
            }
        }

        throw new ProjectException(
            'Only an organization representative or a platform administrator may create a project.',
            403,
            'PROJECT_CREATE_FORBIDDEN',
        );
    }

    private function assertMayManage(Project $project, User $actor): void
    {
        if ($this->isPlatformAdmin($actor)) {
            return;
        }

        if ((int) $project->owner_id === (int) $actor->id) {
            return;
        }

        throw new ProjectException(
            'You are not authorized to manage this project.',
            403,
            'PROJECT_NOT_OWNED',
            ['project_id' => $project->id],
        );
    }

    private function assertMayReview(Project $project, User $actor): void
    {
        if (! $this->isPlatformAdmin($actor)) {
            throw new ProjectException(
                'Only a platform administrator may review a project.',
                403,
                'PROJECT_REVIEW_FORBIDDEN',
            );
        }
    }

    private function isPlatformAdmin(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    private function assertEditable(Project $project): void
    {
        if ($project->isEditable()) {
            return;
        }

        throw new ProjectException(
            'This project can only be edited while it is a draft or has changes requested.',
            422,
            'PROJECT_NOT_EDITABLE',
            [
                'project_id' => $project->id,
                'status' => $project->status,
                'editable_statuses' => Project::EDITABLE_STATUSES,
            ],
        );
    }

    private function assertTransition(Project $project, string $to): void
    {
        if ($project->canTransitionTo($to)) {
            return;
        }

        throw $this->invalidTransition($project, $to, Project::TRANSITIONS[$project->status] ?? []);
    }

    private function invalidTransition(Project $project, string $to, array $allowed): ProjectException
    {
        if ($project->status === Project::STATUS_APPROVED) {
            return new ProjectException(
                'This project has already been approved.',
                422,
                'PROJECT_INVALID_TRANSITION',
                [
                    'project_id' => $project->id,
                    'status' => $project->status,
                    'requested_status' => $to,
                    'allowed_transitions' => $allowed,
                ],
            );
        }

        return new ProjectException(
            'That lifecycle transition is not allowed.',
            422,
            'PROJECT_INVALID_TRANSITION',
            [
                'project_id' => $project->id,
                'status' => $project->status,
                'requested_status' => $to,
                'allowed_transitions' => $allowed,
            ],
        );
    }

    private function completenessGaps(Project $project): array
    {
        $gaps = [];

        if (! in_array($project->type, ['simulation', 'company_sponsored'], true)) {
            $gaps[] = 'type';
        }

        if (blank($project->title)) {
            $gaps[] = 'title';
        }

        if (blank($project->description)) {
            $gaps[] = 'description';
        }

        if (blank($project->objectives)) {
            $gaps[] = 'objectives';
        }

        if ((int) $project->capacity < 1) {
            $gaps[] = 'capacity';
        }

        if ($project->requiredSkills()->count() < 1) {
            $gaps[] = 'required_skills';
        }

        if ($project->projectRoles()->count() < 1) {
            $gaps[] = 'roles';
        }

        if ($project->start_date === null) {
            $gaps[] = 'start_date';
        }

        if ($project->end_date === null) {
            $gaps[] = 'end_date';
        }

        if ($project->start_date !== null && $project->end_date !== null && $project->end_date->lt($project->start_date)) {
            $gaps[] = 'end_date_before_start_date';
        }

        if ($project->application_deadline === null) {
            $gaps[] = 'application_deadline';
        }

        if ($project->application_deadline !== null && $project->start_date !== null && $project->application_deadline->gt($project->start_date)) {
            $gaps[] = 'application_deadline_after_start_date';
        }

        if ($project->application_deadline !== null && $project->application_deadline->lt(today())) {
            $gaps[] = 'application_deadline_in_the_past';
        }

        if ($project->end_date !== null && $project->end_date->lt(today())) {
            $gaps[] = 'end_date_in_the_past';
        }

        return $gaps;
    }

    private function assertComplete(Project $project, string $stage): void
    {
        $gaps = $this->completenessGaps($project);

        if ($gaps === []) {
            return;
        }

        throw new ProjectException(
            'The project is not complete enough to be '.$stage.'.',
            422,
            'PROJECT_INCOMPLETE',
            [
                'project_id' => $project->id,
                'missing' => $gaps,
            ],
        );
    }

    private function assertDateOrdering(Project $project): void
    {
        if ($project->start_date !== null && $project->end_date !== null && $project->end_date->lt($project->start_date)) {
            throw new ProjectException(
                'The project end date must not be before its start date.',
                422,
                'PROJECT_INVALID_DATES',
                [
                    'project_id' => $project->id,
                    'start_date' => $project->start_date->toDateString(),
                    'end_date' => $project->end_date->toDateString(),
                ],
            );
        }
    }

    private function resolveOwnership(
        User $actor,
        string $type,
        mixed $requestedOrganizationId,
        mixed $requestedOwnerId,
        ?int $currentOwnerId = null,
    ): array {
        if ($type === self::TYPE_COMPANY_SPONSORED) {
            return $this->resolveCompanySponsoredOwnership($actor, $requestedOrganizationId, $requestedOwnerId);
        }

        if ($type !== self::TYPE_SIMULATION) {
            throw new ProjectException(
                'Unsupported project type.',
                422,
                'PROJECT_TYPE_INVALID',
                ['type' => $type],
            );
        }

        return $this->resolveSimulationOwnership($actor, $requestedOrganizationId, $currentOwnerId);
    }

    private function resolveCompanySponsoredOwnership(User $actor, mixed $requestedOrganizationId, mixed $requestedOwnerId): array
    {
        if (! $this->isPlatformAdmin($actor)) {
            $administered = $this->administeredOrganization($actor);

            if ($administered === null) {
                throw new ProjectException(
                    'Your account does not administer an active organization.',
                    403,
                    'PROJECT_ORGANIZATION_REQUIRED',
                );
            }

            $organization = $this->assertCompanySponsor($administered);

            return ['organization_id' => (int) $organization->id, 'owner_id' => (int) $actor->id];
        }

        if ($requestedOrganizationId === null) {
            throw new ProjectException(
                'A company_sponsored project must name the sponsoring organization.',
                422,
                'PROJECT_ORGANIZATION_REQUIRED',
            );
        }

        if ($requestedOwnerId === null) {
            throw new ProjectException(
                'A company_sponsored project must name the company representative who will own it.',
                422,
                'PROJECT_OWNER_REQUIRED',
            );
        }

        $organization = $this->assertCompanySponsor((int) $requestedOrganizationId);

        $owner = User::withTrashed()->find((int) $requestedOwnerId);

        if ($owner === null || $owner->trashed()) {
            throw new ProjectException(
                'The selected project owner does not exist.',
                422,
                'PROJECT_OWNER_INVALID',
                ['owner_id' => (int) $requestedOwnerId],
            );
        }

        if ((int) $owner->id === (int) $actor->id) {
            throw new ProjectException(
                'A company_sponsored project must be owned by a representative of the sponsoring company, not by the administrator creating it.',
                422,
                'PROJECT_OWNER_MUST_BE_COMPANY_REPRESENTATIVE',
                ['owner_id' => (int) $owner->id],
            );
        }

        $this->assertRepresentsOrganization($owner, $organization);

        return ['organization_id' => (int) $organization->id, 'owner_id' => (int) $owner->id];
    }

    private function resolveSimulationOwnership(User $actor, mixed $requestedOrganizationId, ?int $currentOwnerId): array
    {
        $organizationId = $requestedOrganizationId === null
            ? null
            : $this->resolveAdministeredOrganizationId($actor, (int) $requestedOrganizationId);

        return [
            'organization_id' => $organizationId,
            'owner_id' => $currentOwnerId ?? (int) $actor->id,
        ];
    }

    private function assertCompanySponsor(int|Organization $organization): Organization
    {
        $model = $organization instanceof Organization
            ? $organization
            : Organization::find($organization);

        if ($model === null) {
            throw new ProjectException(
                'The sponsoring organization does not exist.',
                422,
                'PROJECT_ORGANIZATION_NOT_FOUND',
            );
        }

        if ($model->type !== 'company') {
            throw new ProjectException(
                'A company_sponsored project must be sponsored by a company.',
                422,
                'PROJECT_ORGANIZATION_NOT_A_COMPANY',
                ['organization_id' => $model->id, 'organization_type' => $model->type],
            );
        }

        if ($model->verification_status !== 'verified') {
            throw new ProjectException(
                'The sponsoring organization has not been approved yet.',
                422,
                'PROJECT_ORGANIZATION_NOT_VERIFIED',
                [
                    'organization_id' => $model->id,
                    'verification_status' => $model->verification_status,
                ],
            );
        }

        return $model;
    }

    private function assertRepresentsOrganization(User $owner, Organization $organization): void
    {
        $isRepresentative = $organization->members()
            ->where('users.id', $owner->id)
            ->wherePivot('role_in_org', 'admin')
            ->wherePivot('status', 'active')
            ->exists();

        if ($isRepresentative) {
            return;
        }

        throw new ProjectException(
            'The selected owner is not an active administrator of the sponsoring organization.',
            422,
            'PROJECT_OWNER_NOT_ORGANIZATION_REPRESENTATIVE',
            [
                'organization_id' => $organization->id,
                'owner_id' => $owner->id,
            ],
        );
    }

    private function administeredOrganization(User $actor): ?Organization
    {
        return $actor->organizations()
            ->wherePivot('role_in_org', 'admin')
            ->wherePivot('status', 'active')
            ->first();
    }

    private function resolveAdministeredOrganizationId(User $actor, int $organizationId): int
    {
        if ($this->isPlatformAdmin($actor)) {
            if (! Organization::whereKey($organizationId)->exists()) {
                throw new ProjectException(
                    'The organization does not exist.',
                    422,
                    'PROJECT_ORGANIZATION_NOT_FOUND',
                    ['organization_id' => $organizationId],
                );
            }

            return $organizationId;
        }

        $administered = $this->administeredOrganization($actor);

        if ($administered === null || (int) $administered->id !== $organizationId) {
            throw new ProjectException(
                'You do not administer that organization.',
                403,
                'PROJECT_ORGANIZATION_NOT_ADMINISTERED',
                ['organization_id' => $organizationId],
            );
        }

        return $organizationId;
    }

    private function syncRequiredSkills(Project $project, array $skills): void
    {
        $project->requiredSkills()->delete();

        foreach ($skills as $skill) {
            $minimumLevel = $skill['minimum_required_level'] ?? $skill['minimum_level'] ?? 0;

            ProjectRequiredSkill::create([
                'project_id' => $project->id,
                'skill_id' => (int) $skill['skill_id'],
                'minimum_level' => (float) $minimumLevel,
                'is_critical_entry' => (bool) ($skill['is_critical_entry'] ?? false),
            ]);
        }

        $project->unsetRelation('requiredSkills');
    }

    private function applyCareerRoleProjection(Project $project, mixed $careerRoleId): void
    {
        if ($careerRoleId === null || ! is_numeric($careerRoleId)) {
            return;
        }

        $project->role = $this->careerRoleTitle((int) $careerRoleId);
    }

    private function careerRoleTitle(int $careerRoleId): ?string
    {
        if (! array_key_exists($careerRoleId, $this->careerRoleTitles)) {
            $title = CareerRole::query()->whereKey($careerRoleId)->value('title');

            $this->careerRoleTitles[$careerRoleId] = (string) $title;
        }

        return $this->careerRoleTitles[$careerRoleId];
    }

    private function assertRequiredSkillsBelongToCareerRole(Project $project): void
    {
        $careerRoleId = $project->career_role_id;

        if ($careerRoleId === null) {
            return;
        }

        $skillIds = $project->requiredSkills()->pluck('skill_id')->map(fn ($id) => (int) $id)->all();

        if ($skillIds === []) {
            return;
        }

        $mapped = CareerRoleSkill::query()
            ->where('career_role_id', (int) $careerRoleId)
            ->whereIn('skill_id', $skillIds)
            ->pluck('skill_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $invalid = array_values(array_diff($skillIds, $mapped));

        if ($invalid === []) {
            return;
        }

        throw new ProjectException(
            'One or more required skills are not part of the selected career role.',
            422,
            'PROJECT_SKILL_NOT_IN_CAREER_ROLE',
            [
                'project_id' => $project->id,
                'career_role_id' => (int) $careerRoleId,
                'skill_ids' => $invalid,
            ],
        );
    }

    private function syncRoles(Project $project, array $roles): void
    {
        $project->projectRoles()->delete();

        foreach ($roles as $role) {
            ProjectRole::create([
                'project_id' => $project->id,
                'title' => $role['title'],
                'description' => $role['description'] ?? null,
                'is_active' => (bool) ($role['is_active'] ?? true),
            ]);
        }

        $project->unsetRelation('projectRoles');
    }

    private function syncEligibilityConstraints(Project $project, array $constraints): void
    {
        $project->eligibilityConstraints()->delete();

        foreach ($constraints as $constraint) {
            ProjectEligibilityConstraint::create([
                'project_id' => $project->id,
                'constraint_type' => $constraint['constraint_type'],
                'value' => (string) $constraint['value'],
            ]);
        }

        $project->unsetRelation('eligibilityConstraints');
    }

    private function writableAttributes(array $data): array
    {
        return array_intersect_key($data, array_flip(self::WRITABLE_ATTRIBUTES));
    }

    private function forResponse(Project $project): Project
    {
        return $project->fresh(['organization:id,name', 'requiredSkills.skill:id,name', 'projectRoles', 'eligibilityConstraints', 'careerRole:id,title,slug,version']);
    }
}
