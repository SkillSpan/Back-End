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

class ApplicationService
{
    use AuditsActions;

    public function __construct(
        private readonly ProjectAvailabilityService $availabilityService = new ProjectAvailabilityService,
        private readonly ProjectEligibilityService $eligibilityService = new ProjectEligibilityService,
        private readonly ProjectCapacityPolicy $capacityPolicy = new ProjectCapacityPolicy,
        private readonly NotificationService $notificationService = new NotificationService,
    ) {}

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

        $availability = $this->availabilityService->check($project);

        if (! $availability->available) {
            throw new ApplicationException(
                'This project is not currently accepting applications.',
                422,
                'PROJECT_NOT_AVAILABLE',
                ['reasons' => $availability->reasons],
            );
        }

        $projectRole = $this->resolveProjectRole($project, $payload['project_role_id'] ?? null);
        $recommendation = $this->resolveRecommendation($project, $learner, $payload['recommendation_id'] ?? null);

        if ($this->hasActiveApplication($project, $learner)) {
            throw new ApplicationException(
                'You already have an active application for this project.',
                409,
                'APPLICATION_DUPLICATE',
                ['project_id' => $project->id],
            );
        }

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

                $application->save();

                return $application;
            });
        } catch (QueryException $e) {
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

        return $application;
    }

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
                'reason' => $reason,
            ],
            'project_application',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $application->fresh();
    }

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

    public function forLearner(User $learner, ?string $status = null, int $perPage = 15)
    {
        return Application::query()
            ->where('applicant_id', $learner->id)
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->with(['project.organization:id,name', 'projectRole'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

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

    public function forViewer(Application $application, User $actor): Application
    {
        $isApplicant = (int) $application->applicant_id === (int) $actor->id;

        $isProjectOwner = $application->project !== null
            && (int) $application->project->owner_id === (int) $actor->id;

        if ($isApplicant || $isProjectOwner || $actor->hasRole('admin')) {
            return $application;
        }

        throw new ApplicationException(
            'You are not authorized to view this application.',
            403,
            'APPLICATION_VIEW_FORBIDDEN',
            ['application_id' => $application->id],
        );
    }

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

    private function notifyApplicant(Application $application, string $status): void
    {
        $project = $application->project;

        $this->notificationService->dispatch(
            userId: (int) $application->applicant_id,
            category: 'application',
            title: 'Your application was updated',
            body: 'Your application for '.($project?->title ?? 'a project').' is now '.$status.'.',
            link: '/applications/'.$application->id,
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

    private function fingerprint(array $payload): string
    {
        $normalized = $this->normalizeForFingerprint([
            'project_role_id' => $payload['project_role_id'] ?? null,
            'application_data' => $payload['application_data'] ?? null,
            'recommendation_id' => $payload['recommendation_id'] ?? null,
        ]);

        return hash('sha256', (string) json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

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
