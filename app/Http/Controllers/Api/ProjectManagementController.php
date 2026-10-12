<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ProjectException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectReviewDecisionRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\Projects\ProjectLifecycleService;
use App\Support\SafeLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Project Management Workflow (Pilot) — the owner/admin side of a project:
 * create, update, submit, approve, request changes, reject, open.
 *
 * Thin controller, same convention as ProjectController /
 * ApplicationController: validation in FormRequests, orchestration in
 * ProjectLifecycleService, presentation in ProjectResource, and every failure
 * mapped onto the shared `code` / `message` / `request_id` envelope.
 *
 * AUTHORIZATION SPLIT
 * -------------------
 * The `role` middleware accepts a single slug, so a multi-role rule (owner OR
 * platform admin, or company_admin/university_admin/admin for creation) cannot
 * be expressed on the route. Following the precedent already set by
 * ApplicationController's owner-side endpoints, the rules live in the service:
 *
 *   - create, update, submit, open  → owner or platform admin
 *   - approve, request changes, reject → platform admin (the routes also carry
 *                                     the `admin` middleware as a first line)
 *
 * The learner-facing catalog endpoints in ProjectController are untouched and
 * keep their own access rules — management responses reuse the same
 * ProjectResource, so the project contract does not fork.
 */
class ProjectManagementController extends Controller
{
    public function __construct(
        private readonly ProjectLifecycleService $lifecycleService,
    ) {}

    /**
     * POST /api/v1/projects
     */
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

    /**
     * PATCH /api/v1/projects/{project}
     */
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

    /**
     * POST /api/v1/projects/{project}/submit — draft | changes_requested → submitted.
     */
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

    /**
     * POST /api/v1/projects/{project}/approve — submitted → approved.
     */
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

            return $this->projectResponse($approved, 'Project approved successfully.', 200, $requestId);
        } catch (ProjectException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id, 'project_id' => $project]);
        }
    }

    /**
     * POST /api/v1/projects/{project}/request-changes — submitted → changes_requested.
     */
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

            return $this->projectResponse($changed, 'Changes requested on the project.', 200, $requestId);
        } catch (ProjectException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id, 'project_id' => $project]);
        }
    }

    /**
     * POST /api/v1/projects/{project}/reject — submitted → rejected.
     */
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

            return $this->projectResponse($rejected, 'Project rejected.', 200, $requestId);
        } catch (ProjectException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id, 'project_id' => $project]);
        }
    }

    /**
     * POST /api/v1/projects/{project}/open — approved → open.
     */
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

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Shared plumbing for the two transitions that take no reviewer input
     * (submit, open), so they cannot drift from the ones that do.
     *
     * @param  callable(Project, Request, string): Project  $action
     */
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
