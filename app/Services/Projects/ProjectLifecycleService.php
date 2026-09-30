<?php

namespace App\Services\Projects;

use App\Exceptions\ProjectException;
use App\Models\Project;
use App\Models\ProjectEligibilityConstraint;
use App\Models\ProjectRequiredSkill;
use App\Models\ProjectRole;
use App\Models\User;
use App\Traits\AuditsActions;
use Illuminate\Support\Facades\DB;

/**
 * Project Management Workflow for the Pilot.
 *
 *   draft → submitted → approved → open
 *              ↓  ↓
 *   changes_requested  rejected
 *
 * Orchestration only. Everything that already exists is delegated rather than
 * re-implemented:
 *
 *   - the lifecycle itself          → Project::TRANSITIONS / canTransitionTo()
 *   - the transition legality check → Project::canTransitionTo()
 *   - audit rows                    → AuditsActions trait → audit_events
 *   - learner-facing discoverability → ProjectAccessService (unchanged: only
 *                                     `open` projects are discoverable)
 *   - availability / eligibility / capacity → untouched, see the note below
 *
 * AUTHORIZATION
 * -------------
 * The `role` middleware takes a SINGLE slug, so a multi-role rule cannot be
 * expressed on the route. Following the precedent already set by
 * ApplicationService (whose owner-side endpoints are deliberately not
 * role-gated and authorize inside the service), every rule lives here:
 *
 *   - create            → platform admin, company_admin or university_admin
 *   - update/submit/open→ platform admin OR the project's own owner
 *   - approve/request changes/reject → platform admin only
 *
 * The approval routes additionally carry the `admin` middleware, so the
 * service check is the second line of defence, not the only one.
 *
 * WHAT THIS SERVICE DELIBERATELY DOES NOT DO
 * ------------------------------------------
 *   - It never moves a project to `active`. Approving yields `approved`;
 *     opening yields `open`; execution states are out of Pilot scope.
 *   - It never writes `closed` — that legacy value is undecided.
 *   - It never changes a project's type outside the editable window, so the
 *     type cannot change once the project is open and receiving applications.
 */
class ProjectLifecycleService
{
    use AuditsActions;

    /** Role slugs that may create projects on behalf of an organization. */
    private const MANAGEMENT_ROLES = ['company_admin', 'university_admin'];

    /**
     * Project columns a caller may set. Everything else — status, owner_id,
     * organization_id, version, approval fields — is derived here, so a
     * payload can never mass-assign its way into the lifecycle.
     *
     * @var array<int, string>
     */
    private const WRITABLE_ATTRIBUTES = [
        'type', 'domain', 'title', 'description', 'objectives', 'learning_outcomes',
        'difficulty', 'work_mode', 'role', 'schedule', 'capacity', 'min_team_size',
        'start_date', 'end_date', 'application_deadline', 'confidentiality',
    ];

    /**
     * Create a project. It always starts as a DRAFT — a project is never
     * created open, and never created already submitted.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(
        User $actor,
        array $data,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $this->assertMayCreate($actor);

        $organizationId = $this->resolveOrganizationId($actor, $data['organization_id'] ?? null);

        $project = DB::transaction(function () use ($actor, $data, $organizationId): Project {
            $project = new Project($this->writableAttributes($data));
            $project->owner_id = $actor->id;
            $project->organization_id = $organizationId;
            $project->status = Project::STATUS_DRAFT;
            $project->version = 1;
            $project->save();

            $this->syncRequiredSkills($project, $data['required_skills'] ?? []);
            $this->syncRoles($project, $data['roles'] ?? []);
            $this->syncEligibilityConstraints($project, $data['eligibility_constraints'] ?? []);

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

    /**
     * Update a project the actor manages, within the revisable window.
     *
     * @param  array<string, mixed>  $data
     */
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

        $before = $project->only(array_merge(self::WRITABLE_ATTRIBUTES, ['version']));
        $versionedBefore = $project->only(Project::VERSIONED_FIELDS);

        DB::transaction(function () use ($project, $data): void {
            $project->fill($this->writableAttributes($data));
            $project->save();

            // The child sets are replaced wholesale, but only when the caller
            // actually supplied them — an omitted key means "leave as is",
            // which is what makes a partial update safe.
            if (array_key_exists('required_skills', $data)) {
                $this->syncRequiredSkills($project, $data['required_skills']);
            }

            if (array_key_exists('roles', $data)) {
                $this->syncRoles($project, $data['roles']);
            }

            if (array_key_exists('eligibility_constraints', $data)) {
                $this->syncEligibilityConstraints($project, $data['eligibility_constraints']);
            }

            $this->assertDateOrdering($project);
        });

        // The version column already exists and applications already store the
        // value they applied against, so it is bumped when — and only when —
        // something in the matching snapshot actually changed. Descriptive
        // edits must not churn it.
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

    /**
     * Submit for review: draft | changes_requested → submitted.
     *
     * A project that is not complete is NOT submitted — the status is left
     * untouched and every missing piece is reported at once, so the owner can
     * fix them in one pass.
     */
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

    /**
     * Approve a submitted project. It becomes `approved`, NOT `active`, and it
     * is NOT opened automatically — opening is a separate, explicit step.
     */
    public function approve(
        Project $project,
        User $actor,
        ?string $reason = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $this->assertMayReview($actor);
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

    /**
     * Send a submitted project back to its owner: submitted → changes_requested.
     * The reason is mandatory here — the owner cannot act on "changes requested"
     * without knowing what to change.
     */
    public function requestChanges(
        Project $project,
        User $actor,
        string $reason,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $this->assertMayReview($actor);
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

    /**
     * Reject a submitted project: submitted → rejected.
     */
    public function reject(
        Project $project,
        User $actor,
        ?string $reason = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $this->assertMayReview($actor);
        $this->assertTransition($project, Project::STATUS_REJECTED);

        return $this->transition($project, $actor, Project::STATUS_REJECTED, $reason, 'project.rejected', [], $requestId, $ipAddress, $userAgent);
    }

    /**
     * Open an approved project: approved → open.
     *
     * This is the step that makes a project discoverable and lets matching and
     * applications operate — discoverability itself stays owned by
     * ProjectAccessService, which only ever selects `open` projects.
     */
    public function open(
        Project $project,
        User $actor,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Project {
        $this->assertMayManage($project, $actor);
        $this->assertTransition($project, Project::STATUS_OPEN);

        return $this->transition($project, $actor, Project::STATUS_OPEN, null, 'project.opened', [], $requestId, $ipAddress, $userAgent);
    }

    // -----------------------------------------------------------------
    // Transitions
    // -----------------------------------------------------------------

    /**
     * The single writer for every lifecycle move.
     *
     * The status change is a conditional UPDATE (`WHERE status = <from>`) so
     * two concurrent requests — a double-click, or a retry after a timeout —
     * cannot both apply a transition. The loser gets a 409 describing the real
     * current state instead of silently re-running the side effects.
     *
     * @param  array<string, mixed>  $extra  extra columns written with the status
     */
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
                    // The projects table has no decision-reason column and the
                    // Pilot did not ask for one, so the reason is preserved in
                    // the audit row rather than inventing a schema change —
                    // the same choice ApplicationService::withdraw() made.
                    'reason' => $reason,
                ],
                $extra,
            ),
            'project_lifecycle',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $this->forResponse($project);
    }

    // -----------------------------------------------------------------
    // Authorization
    // -----------------------------------------------------------------

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

    /**
     * Owner or platform administrator. Ownership is checked against the stored
     * owner_id, so a project belonging to another organization's user can never
     * be managed from here (no IDOR).
     */
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

    private function assertMayReview(User $actor): void
    {
        if ($this->isPlatformAdmin($actor)) {
            return;
        }

        throw new ProjectException(
            'Only a platform administrator may review a project.',
            403,
            'PROJECT_REVIEW_FORBIDDEN',
        );
    }

    private function isPlatformAdmin(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    // -----------------------------------------------------------------
    // Business rules
    // -----------------------------------------------------------------

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

        throw new ProjectException(
            'That lifecycle transition is not allowed.',
            422,
            'PROJECT_INVALID_TRANSITION',
            [
                'project_id' => $project->id,
                'status' => $project->status,
                'requested_status' => $to,
                'allowed_transitions' => Project::TRANSITIONS[$project->status] ?? [],
            ],
        );
    }

    /**
     * Everything a project needs before a reviewer may look at it. Collected as
     * a list rather than failing on the first gap, so the owner sees all of it.
     *
     * @return array<int, string>
     */
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

        // Applying must close before the work starts, otherwise the project
        // could be opened while already past its own deadline.
        if ($project->application_deadline !== null && $project->start_date !== null && $project->application_deadline->gt($project->start_date)) {
            $gaps[] = 'application_deadline_after_start_date';
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

    /**
     * A date range must make sense on its own before anything else looks at it.
     *
     * Evaluated against the FINAL state (the stored row merged with the
     * payload), because an update may supply only one of the two dates — a
     * request-level `after_or_equal` cannot see the stored sibling and would
     * compare against null.
     */
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

    /**
     * The project's organization.
     *
     * An organization representative's project ALWAYS belongs to the
     * organization they administer — a supplied `organization_id` is ignored,
     * so a company admin cannot publish into someone else's organization.
     * A platform administrator may target any organization, or none (a
     * simulation), which is why the column is nullable.
     */
    private function resolveOrganizationId(User $actor, mixed $requested): ?int
    {
        $administered = $actor->organizations()
            ->wherePivot('role_in_org', 'admin')
            ->wherePivot('status', 'active')
            ->first();

        if ($administered !== null) {
            return (int) $administered->id;
        }

        if ($this->isPlatformAdmin($actor)) {
            return $requested === null ? null : (int) $requested;
        }

        throw new ProjectException(
            'Your account does not administer an active organization.',
            403,
            'PROJECT_ORGANIZATION_REQUIRED',
        );
    }

    // -----------------------------------------------------------------
    // Child collections
    // -----------------------------------------------------------------

    /**
     * Replace the project's required skills.
     *
     * @param  array<int, array<string, mixed>>  $skills
     */
    private function syncRequiredSkills(Project $project, array $skills): void
    {
        $project->requiredSkills()->delete();

        foreach ($skills as $skill) {
            ProjectRequiredSkill::create([
                'project_id' => $project->id,
                'skill_id' => (int) $skill['skill_id'],
                'minimum_level' => $skill['minimum_level'] ?? 0,
                'is_critical_entry' => (bool) ($skill['is_critical_entry'] ?? false),
            ]);
        }

        $project->unsetRelation('requiredSkills');
    }

    /**
     * @param  array<int, array<string, mixed>>  $roles
     */
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

    /**
     * @param  array<int, array<string, mixed>>  $constraints
     */
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

    /**
     * Only the columns a caller may set — status, owner, organization and
     * version are deliberately excluded.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function writableAttributes(array $data): array
    {
        return array_intersect_key($data, array_flip(self::WRITABLE_ATTRIBUTES));
    }

    /**
     * The relations ProjectResource presents, so every management response has
     * the same shape as the learner-facing details response.
     */
    private function forResponse(Project $project): Project
    {
        return $project->fresh(['organization:id,name', 'requiredSkills.skill:id,name', 'projectRoles', 'eligibilityConstraints']);
    }
}
