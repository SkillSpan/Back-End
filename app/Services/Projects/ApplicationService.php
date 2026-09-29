<?php

namespace App\Services\Projects;

use App\Exceptions\ApplicationException;
use App\Models\Application;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\NotificationService;
use App\Traits\AuditsActions;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * US-MATCH-02 — the project-application workflow.
 *
 * Orchestration only. Every rule it applies already exists somewhere else and
 * is delegated rather than re-implemented:
 *
 *   - project state + deadline      → ProjectAvailabilityService (reused as-is)
 *   - skills / constraints / team   → ProjectEligibilityService (reused as-is)
 *   - seats                         → ProjectCapacityPolicy (the single seam)
 *   - notifications                 → NotificationService (preference-aware)
 *   - audit rows                    → AuditsActions trait → audit_events
 *
 * Nothing here decides what "full" means, and nothing here counts applications
 * to do it — that would duplicate the capacity policy.
 *
 * ORDER OF CHECKS (deliberate)
 * ----------------------------
 *   1. idempotent replay     — a retry must return the original result and must
 *                              NOT be re-validated, because the world may have
 *                              changed since (e.g. the project filled up).
 *   2. availability          — is the project open and within its deadline?
 *   3. client references     — does the requested role belong to this project
 *                              and is it still active; does the referenced
 *                              recommendation belong to this learner and point
 *                              at this project? Both are validated together so a
 *                              bad reference is never masked by a later
 *                              business-state error.
 *   4. duplicate             — at most one ACTIVE application per learner per
 *                              project (the DB enforces this too, via
 *                              unique(project_id, applicant_id, active_key)).
 *   5. capacity              — only if the project is actually full.
 *   6. eligibility           — critical skills, constraints, team conflicts.
 *   7. persist + audit + notify, in one transaction.
 *
 * Steps 2/4/6 come from existing services; their reason strings are surfaced
 * verbatim in `details.reasons` rather than being reworded, so the frontend
 * sees exactly what the existing catalog/eligibility endpoints report.
 */
class ApplicationService
{
    use AuditsActions;

    public function __construct(
        private readonly ProjectAvailabilityService $availabilityService = new ProjectAvailabilityService,
        private readonly ProjectEligibilityService $eligibilityService = new ProjectEligibilityService,
        private readonly ProjectCapacityPolicy $capacityPolicy = new ProjectCapacityPolicy,
        private readonly NotificationService $notificationService = new NotificationService,
    ) {}

    /**
     * Submit an application for a project.
     *
     * @param  array{project_role_id?: int|null, application_data?: array|null, recommendation_id?: int|null, idempotency_key?: string|null}  $payload
     *
     * @throws ApplicationException on any domain failure.
     */
    public function submit(
        Project $project,
        User $learner,
        array $payload = [],
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Application {
        $idempotencyKey = $this->normalizeKey($payload['idempotency_key'] ?? null);
        $fingerprint = $idempotencyKey === null ? null : $this->fingerprint($payload);

        // 1. Idempotent replay. Returns the ORIGINAL row untouched.
        if ($idempotencyKey !== null) {
            $existing = Application::where('applicant_id', $learner->id)
                ->where('project_id', $project->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                if ($existing->request_fingerprint !== null && $existing->request_fingerprint !== $fingerprint) {
                    throw new ApplicationException(
                        'This idempotency key was already used with a different request body.',
                        422,
                        'APPLICATION_IDEMPOTENCY_CONFLICT',
                        ['idempotency_key' => $idempotencyKey, 'application_id' => $existing->id],
                    );
                }

                return $existing;
            }
        }

        // 2. Availability — status + deadline. Reused service, verbatim reasons.
        $availability = $this->availabilityService->check($project);

        if (! $availability->available) {
            throw new ApplicationException(
                'This project is not currently accepting applications.',
                422,
                'PROJECT_NOT_AVAILABLE',
                ['reasons' => $availability->reasons],
            );
        }

        // 3. Client-supplied references. Both are validated HERE, together,
        //    BEFORE any business-state check — a bad reference must be reported
        //    as a bad reference, not masked by a duplicate/capacity error that
        //    happens to be true as well.
        //      - the role must belong to THIS project and still be active
        //      - the recommendation must belong to THIS learner and point at
        //        THIS project
        $projectRole = $this->resolveProjectRole($project, $payload['project_role_id'] ?? null);
        $recommendation = $this->resolveRecommendation($project, $learner, $payload['recommendation_id'] ?? null);

        // 4. Duplicate active application.
        if ($this->hasActiveApplication($project, $learner)) {
            throw new ApplicationException(
                'You already have an active application for this project.',
                409,
                'APPLICATION_DUPLICATE',
                ['project_id' => $project->id],
            );
        }

        // 5. Capacity — decided ONLY by the policy seam.
        if ($this->capacityPolicy->blocksNewApplication($project)) {
            $check = $this->capacityPolicy->check($project);

            throw new ApplicationException(
                'This project has no seats left.',
                409,
                'PROJECT_FULL',
                [
                    'capacity' => $check->capacity,
                    'seats_taken' => $check->seats_taken,
                    'seats_remaining' => $check->seats_remaining,
                    'reasons' => $check->reasons,
                ],
            );
        }

        // 6. Eligibility — critical skills, constraints, team conflicts.
        //    The duplicate rule inside this service is already satisfied by
        //    step 4, so it never fires twice.
        $eligibility = $this->eligibilityService->check($project, $learner);

        if (! $eligibility->eligible) {
            throw new ApplicationException(
                'You are not eligible to apply for this project.',
                422,
                'APPLICATION_NOT_ELIGIBLE',
                [
                    'reasons' => $eligibility->reasons,
                    'skill_failures' => $eligibility->skill_failures,
                ],
            );
        }

        $applicationData = $payload['application_data'] ?? null;

        try {
            $application = DB::transaction(function () use (
                $project, $learner, $projectRole, $recommendation, $applicationData, $idempotencyKey, $fingerprint
            ): Application {
                $application = new Application([
                    'project_id' => $project->id,
                    'applicant_id' => $learner->id,
                    'project_role_id' => $projectRole?->id,
                    'status' => Application::STATUS_SUBMITTED,
                    'project_version' => $project->version,
                    'application_data' => $applicationData,
                    'recommendation_id' => $recommendation?->id,
                    'recommendation_algorithm_version' => $recommendation?->algorithm_version,
                    'recommendation_configuration_version' => $recommendation?->configuration_version,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                ]);

                // active_key and submitted_at are derived by the model.
                $application->save();

                return $application;
            });
        } catch (QueryException $e) {
            // Two concurrent submissions for the same learner+project can both
            // pass step 4 and then race on the unique index. Translate the
            // database's answer into the same domain error the sequential path
            // produces, rather than leaking a 500.
            if ($this->isUniqueViolation($e)) {
                throw new ApplicationException(
                    'You already have an active application for this project.',
                    409,
                    'APPLICATION_DUPLICATE',
                    ['project_id' => $project->id],
                    $e,
                );
            }

            throw $e;
        }

        $this->audit(
            $learner->id,
            'application.submitted',
            'application',
            $application->id,
            null,
            [
                'project_id' => $project->id,
                'project_version' => $application->project_version,
                'project_role_id' => $application->project_role_id,
                'status' => $application->status,
                'recommendation_id' => $application->recommendation_id,
                'has_application_data' => $applicationData !== null,
            ],
            'project_application',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        $this->notifyOwner($application, $learner);

        // Deliberately NOT fresh(): the in-memory model already carries every
        // attribute this workflow set (the derived submitted_at and active_key
        // are written by the model's own hooks before the insert), and keeping
        // the original instance lets the controller distinguish a genuine
        // insert from an idempotent replay via wasRecentlyCreated — a replay
        // must not answer 201 for a resource that already existed.
        return $application;
    }

    /**
     * Withdraw the learner's OWN application.
     *
     * Ownership is enforced here, not by the route, because the route only
     * knows the learner role — it cannot know whose application an id refers
     * to. A learner therefore cannot withdraw someone else's application, and
     * the response for a foreign id is deliberately the same 403 shape as a
     * genuine authorization failure.
     */
    public function withdraw(
        Application $application,
        User $learner,
        ?string $reason = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Application {
        if ((int) $application->applicant_id !== (int) $learner->id) {
            throw new ApplicationException(
                'This application does not belong to you.',
                403,
                'APPLICATION_NOT_OWNED',
                ['application_id' => $application->id],
            );
        }

        if (! $application->canTransitionTo(Application::STATUS_WITHDRAWN)) {
            throw new ApplicationException(
                'This application can no longer be withdrawn.',
                422,
                'APPLICATION_NOT_WITHDRAWABLE',
                [
                    'application_id' => $application->id,
                    'status' => $application->status,
                    'allowed_transitions' => Application::TRANSITIONS[$application->status] ?? [],
                ],
            );
        }

        $before = ['status' => $application->status];

        DB::transaction(function () use ($application): void {
            $application->status = Application::STATUS_WITHDRAWN;
            $application->withdrawn_at = now();
            // active_key is recomputed by the model's saving hook, which is what
            // releases the unique slot and (with the default policy) the seat.
            $application->save();
        });

        $this->audit(
            $learner->id,
            'application.withdrawn',
            'application',
            $application->id,
            $before,
            [
                'status' => $application->status,
                'withdrawn_at' => $application->withdrawn_at?->toIso8601String(),
                // The applications table has no withdrawal-reason column and
                // US-MATCH-02 did not ask for one, so the reason is preserved
                // in the audit row rather than inventing a schema change.
                'reason' => $reason,
            ],
            'project_application',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $application->fresh();
    }

    /**
     * Record the project owner's decision on an application.
     *
     * Authorization is explicit ownership of the project — NOT a role. Any
     * authenticated user who owns the project may decide; nobody else may.
     */
    public function decide(
        Application $application,
        User $actor,
        string $status,
        ?string $reason = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Application {
        $project = $application->project;

        if ($project === null || (int) $project->owner_id !== (int) $actor->id) {
            throw new ApplicationException(
                'Only the project owner may decide on its applications.',
                403,
                'APPLICATION_DECISION_FORBIDDEN',
                ['application_id' => $application->id],
            );
        }

        if (! $application->canTransitionTo($status)) {
            throw new ApplicationException(
                'That status transition is not allowed.',
                422,
                'APPLICATION_INVALID_TRANSITION',
                [
                    'application_id' => $application->id,
                    'status' => $application->status,
                    'requested_status' => $status,
                    'allowed_transitions' => Application::TRANSITIONS[$application->status] ?? [],
                ],
            );
        }

        // Awarding a seat must respect the seat limit even when new submissions
        // are still being accepted (reject_submission_when_full = false).
        if ($status === Application::STATUS_ACCEPTED && $this->capacityPolicy->isFull($project)) {
            $check = $this->capacityPolicy->check($project);

            throw new ApplicationException(
                'This project has no seats left.',
                409,
                'PROJECT_FULL',
                [
                    'capacity' => $check->capacity,
                    'seats_taken' => $check->seats_taken,
                    'seats_remaining' => $check->seats_remaining,
                    'reasons' => $check->reasons,
                ],
            );
        }

        $before = ['status' => $application->status, 'decided_by' => $application->decided_by];

        DB::transaction(function () use ($application, $actor, $status, $reason): void {
            $application->status = $status;
            $application->decided_by = $actor->id;
            $application->decided_at = now();

            if ($reason !== null) {
                $application->decision_reason = $reason;
            }

            $application->save();
        });

        $this->audit(
            $actor->id,
            'application.decided',
            'application',
            $application->id,
            $before,
            ['status' => $application->status, 'reason' => $reason],
            'project_application',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        $this->notifyApplicant($application->fresh(), $status);

        return $application->fresh();
    }

    /**
     * The learner's own applications, newest first, PAGINATED.
     *
     * Paginated because a learner accumulates a row per project they ever
     * applied to (including withdrawn ones, which are kept as history), so the
     * response must stay bounded. This is the same convention the existing list
     * endpoints use — RecommendationController and NotificationService::list
     * both paginate with a clamped per-page.
     */
    public function forLearner(User $learner, ?string $status = null, int $perPage = 15)
    {
        return Application::query()
            ->where('applicant_id', $learner->id)
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->with(['project.organization:id,title', 'projectRole'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Applications to a project, for its owner only. Paginated for the same
     * reason: a popular project can have many applicants.
     */
    public function forProject(Project $project, User $actor, int $perPage = 15)
    {
        if ((int) $project->owner_id !== (int) $actor->id) {
            throw new ApplicationException(
                'Only the project owner may view its applications.',
                403,
                'APPLICATION_LIST_FORBIDDEN',
                ['project_id' => $project->id],
            );
        }

        return Application::query()
            ->where('project_id', $project->id)
            ->with(['applicant:id,name,email', 'projectRole'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * At most one ACTIVE application per learner per project. Uses the model's
     * own ACTIVE_STATUSES so the rule cannot drift from the DB constraint.
     */
    private function hasActiveApplication(Project $project, User $learner): bool
    {
        return Application::where('project_id', $project->id)
            ->where('applicant_id', $learner->id)
            ->whereIn('status', Application::ACTIVE_STATUSES)
            ->exists();
    }

    private function resolveProjectRole(Project $project, mixed $projectRoleId): ?ProjectRole
    {
        if ($projectRoleId === null) {
            return null;
        }

        // Scoped through the project's own relation, so a role id belonging to
        // a DIFFERENT project can never be applied with.
        $role = $project->projectRoles()->whereKey((int) $projectRoleId)->first();

        if ($role === null) {
            throw new ApplicationException(
                'The selected role does not belong to this project.',
                422,
                'PROJECT_ROLE_INVALID',
                ['project_role_id' => (int) $projectRoleId, 'project_id' => $project->id],
            );
        }

        if (! $role->isAvailable()) {
            throw new ApplicationException(
                'The selected role is no longer accepting applications.',
                422,
                'PROJECT_ROLE_INACTIVE',
                ['project_role_id' => $role->id],
            );
        }

        return $role;
    }

    /**
     * A recommendation may only be referenced when it belongs to this learner
     * and actually points at this project. Otherwise the learner could attach
     * an arbitrary (or someone else's) recommendation id.
     */
    private function resolveRecommendation(Project $project, User $learner, mixed $recommendationId): ?Recommendation
    {
        if ($recommendationId === null) {
            return null;
        }

        $recommendation = Recommendation::whereKey((int) $recommendationId)
            ->where('user_id', $learner->id)
            ->first();

        if ($recommendation === null) {
            throw new ApplicationException(
                'The referenced recommendation was not found for this learner.',
                422,
                'RECOMMENDATION_NOT_FOUND',
                ['recommendation_id' => (int) $recommendationId],
            );
        }

        if ((int) $recommendation->candidate_id !== (int) $project->id) {
            throw new ApplicationException(
                'The referenced recommendation does not point at this project.',
                422,
                'RECOMMENDATION_PROJECT_MISMATCH',
                [
                    'recommendation_id' => $recommendation->id,
                    'project_id' => $project->id,
                ],
            );
        }

        return $recommendation;
    }

    /**
     * In-app notification to the project owner. Routed through
     * NotificationService so the owner's preferences are honoured, and keyed on
     * the application id so a retry cannot produce a duplicate.
     */
    private function notifyOwner(Application $application, User $learner): void
    {
        $project = $application->project;

        if ($project === null || $project->owner_id === null) {
            return;
        }

        $this->notificationService->dispatch(
            userId: (int) $project->owner_id,
            category: 'application',
            title: 'New application for '.$project->title,
            body: $learner->name.' applied to your project.',
            link: '/projects/'.$project->id.'/applications/'.$application->id,
            eventKey: 'application.submitted:'.$application->id,
        );
    }

    /**
     * In-app notification to the applicant when the owner decides.
     */
    private function notifyApplicant(Application $application, string $status): void
    {
        $project = $application->project;

        $this->notificationService->dispatch(
            userId: (int) $application->applicant_id,
            category: 'application',
            title: 'Your application was updated',
            body: 'Your application for '.($project?->title ?? 'a project').' is now '.$status.'.',
            link: '/applications/'.$application->id,
            // Keyed on the new status, so each distinct decision notifies once
            // while a repeated identical decision does not.
            eventKey: 'application.status:'.$application->id.':'.$status,
        );
    }

    private function normalizeKey(mixed $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $key = trim((string) $key);

        return $key === '' ? null : $key;
    }

    /**
     * Stable hash of the normalized request body, so reusing an idempotency key
     * with a materially different payload can be detected.
     */
    private function fingerprint(array $payload): string
    {
        $normalized = $this->normalizeForFingerprint([
            'project_role_id' => $payload['project_role_id'] ?? null,
            'application_data' => $payload['application_data'] ?? null,
            'recommendation_id' => $payload['recommendation_id'] ?? null,
        ]);

        return hash('sha256', (string) json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Recursively sort map keys so two payloads that differ only in the ORDER
     * their keys were serialized produce the same fingerprint.
     *
     * Why this matters: a client that builds `application_data` from an
     * unordered map (a JS object, a hash) can legitimately emit the same logical
     * body with a different key order on a retry. Without normalization that
     * would be reported as APPLICATION_IDEMPOTENCY_CONFLICT, turning a safe
     * retry into a hard failure. Sorting only the top level is not enough —
     * nested objects need it too.
     *
     * Lists keep their order, because order is meaningful for a list; only maps
     * (string-keyed arrays) are sorted.
     */
    private function normalizeForFingerprint(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->normalizeForFingerprint($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeForFingerprint($item);
        }

        return $value;
    }

    /**
     * Whether a QueryException is a unique-constraint violation, across the
     * drivers this project runs on (SQLite in tests, MySQL in production).
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        if ($sqlState === '23000' || $sqlState === '23505') {
            return true;
        }

        $message = $e->getMessage();

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry');
    }
}
