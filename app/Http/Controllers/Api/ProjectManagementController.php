<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ProjectException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CloneProjectRequest;
use App\Http\Requests\ProjectReviewDecisionRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\TransitionProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\NotificationService;
use App\Services\Projects\ProjectLifecycleService;
use App\Support\SafeLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProjectManagementController extends Controller
{
    public function __construct(
        private readonly ProjectLifecycleService $lifecycleService,
        private readonly NotificationService $notifications,
    ) {}

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $project = $this->lifecycleService->create(
                $request->user(),
                $request->validated(),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->projectResponse($project, 'Project created successfully.', 201, $requestId);
        } catch (ProjectException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id]);
        }
    }

    public function update(UpdateProjectRequest $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);

        $model = Project::query()->find($project);

        if (! $model) {
            return $this->projectNotFound($project, $requestId);
        }

        try {
            $updated = $this->lifecycleService->update(
                $model,
                $request->user(),
                $request->validated(),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->projectResponse($updated, 'Project updated successfully.', 200, $requestId);
        } catch (ProjectException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id, 'project_id' => $project]);
        }
    }

    public function submit(Request $request, int $project): JsonResponse
    {
        return $this->lifecycleAction(
            $request,
            $project,
            fn (Project $model, Request $r, string $requestId) => $this->lifecycleService->submit(
                $model, $r->user(), $requestId, $r->ip(), $r->userAgent()
            ),
            'Project submitted for review.',
        );
    }

    public function approve(ProjectReviewDecisionRequest $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Project::query()->find($project);

        if (! $model) {
            return $this->projectNotFound($project, $requestId);
        }

        try {
            $approved = $this->lifecycleService->approve(
                $model,
                $request->user(),
                $request->validated()['reason'] ?? null,
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            $this->notifyOwner(
                $approved,
                'approved',
                'Your project was approved',
                $approved->title.' is now live for learners.',
            );

            return $this->projectResponse($approved, 'Project approved successfully.', 200, $requestId);
        } catch (ProjectException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id, 'project_id' => $project]);
        }
    }

    public function requestChanges(ProjectReviewDecisionRequest $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Project::query()->find($project);

        if (! $model) {
            return $this->projectNotFound($project, $requestId);
        }

        try {
            $changed = $this->lifecycleService->requestChanges(
                $model,
                $request->user(),
                (string) ($request->validated()['reason'] ?? ''),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            $this->notifyOwner(
                $changed,
                'changes_requested',
                'Changes requested on your project',
                (string) ($request->validated()['reason'] ?? ''),
            );

            return $this->projectResponse($changed, 'Changes requested on the project.', 200, $requestId);
        } catch (ProjectException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id, 'project_id' => $project]);
        }
    }

    public function reject(ProjectReviewDecisionRequest $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Project::query()->find($project);

        if (! $model) {
            return $this->projectNotFound($project, $requestId);
        }

        try {
            $rejected = $this->lifecycleService->reject(
                $model,
                $request->user(),
                $request->validated()['reason'] ?? null,
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            $this->notifyOwner(
                $rejected,
                'rejected',
                'Your project was rejected',
                (string) ($request->validated()['reason'] ?? ''),
            );

            return $this->projectResponse($rejected, 'Project rejected.', 200, $requestId);
        } catch (ProjectException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id, 'project_id' => $project]);
        }
    }

    public function open(Request $request, int $project): JsonResponse
    {
        return $this->lifecycleAction(
            $request,
            $project,
            fn (Project $model, Request $r, string $requestId) => $this->lifecycleService->open(
                $model, $r->user(), $requestId, $r->ip(), $r->userAgent()
            ),
            'Project opened successfully.',
        );
    }

    public function start(Request $request, int $project): JsonResponse
    {
        return $this->lifecycleAction(
            $request,
            $project,
            fn (Project $model, Request $r, string $requestId) => $this->lifecycleService->start(
                $model, $r->user(), $requestId, $r->ip(), $r->userAgent()
            ),
            'Project started successfully.',
        );
    }

    public function duplicate(CloneProjectRequest $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);

        $model = Project::query()->find($project);

        if (! $model) {
            return $this->projectNotFound($project, $requestId);
        }

        try {
            $copy = $this->lifecycleService->clone(
                $model,
                $request->user(),
                $request->validated(),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->projectResponse($copy, 'Project cloned successfully.', 201, $requestId);
        } catch (ProjectException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id, 'project_id' => $project]);
        }
    }

    public function transition(TransitionProjectRequest $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);

        $model = Project::query()->find($project);

        if (! $model) {
            return $this->projectNotFound($project, $requestId);
        }

        $to = (string) $request->validated()['to'];

        try {
            $updated = $this->lifecycleService->transitionTo(
                $model,
                $request->user(),
                $to,
                $request->validated()['reason'] ?? null,
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->projectResponse($updated, $this->transitionMessage($to), 200, $requestId);
        } catch (ProjectException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id, 'project_id' => $project]);
        }
    }

    private function transitionMessage(string $to): string
    {
        return match ($to) {
            Project::STATUS_SUBMITTED => 'Project submitted for review.',
            Project::STATUS_APPROVED => 'Project approved successfully.',
            Project::STATUS_CHANGES_REQUESTED => 'Changes requested on the project.',
            Project::STATUS_REJECTED => 'Project rejected.',
            Project::STATUS_OPEN => 'Project opened successfully.',
            default => 'Project status updated successfully.',
        };
    }

    private function lifecycleAction(Request $request, int $projectId, callable $action, string $message): JsonResponse
    {
        $requestId = $this->requestId($request);

        $model = Project::query()->find($projectId);

        if (! $model) {
            return $this->projectNotFound($projectId, $requestId);
        }

        try {
            return $this->projectResponse($action($model, $request, $requestId), $message, 200, $requestId);
        } catch (ProjectException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id, 'project_id' => $projectId]);
        }
    }

    private function projectResponse(Project $project, string $message, int $status, string $requestId): JsonResponse
    {
        return (new ProjectResource($project))
            ->additional([
                'success' => true,
                'message' => $message,
                'request_id' => $requestId,
            ])
            ->response()
            ->setStatusCode($status)
            ->header('X-Request-ID', $requestId);
    }

    private function notifyOwner(Project $project, string $status, string $title, ?string $body): void
    {
        if ($project->owner_id === null) {
            return;
        }

        $this->notifications->dispatch(
            userId: (int) $project->owner_id,
            category: 'project_status',
            title: $title,
            body: $body === '' ? null : $body,
            link: '/projects/'.$project->id,
            eventKey: 'project.status:'.$project->id.':'.$status,
        );
    }

    private function projectNotFound(int $projectId, string $requestId): JsonResponse
    {
        return $this->errorResponse(
            'PROJECT_NOT_FOUND',
            'The requested project does not exist.',
            404,
            $requestId,
            ['project_id' => $projectId],
        );
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
    }

    private function unexpected(Throwable $e, string $requestId, array $context = []): JsonResponse
    {
        Log::error('Project management request failed.', array_merge([
            'request_id' => $requestId,
            'failure_reason' => SafeLog::reason($e),
        ], $context));

        return $this->errorResponse(
            'PROJECT_REQUEST_FAILED',
            'The project request could not be completed.',
            500,
            $requestId,
        );
    }

    private function errorResponse(
        string $code,
        string $message,
        int $status,
        string $requestId,
        array $details = [],
    ): JsonResponse {
        $payload = [
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ];

        if ($details !== []) {
            $payload['details'] = $details;
        }

        return response()->json($payload, $status, ['X-Request-ID' => $requestId]);
    }
}
