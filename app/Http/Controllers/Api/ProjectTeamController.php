<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ProjectException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTeamAssignmentRequest;
use App\Http\Resources\ProjectTeamResource;
use App\Models\Project;
use App\Services\Projects\ProjectTeamService;
use App\Support\SafeLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Staffing a project — `POST /api/v1/projects/{project}/team/assignments`.
 *
 * Thin adapter, like the rest of the project API: the rules live in
 * ProjectTeamService, the presentation in ProjectTeamResource, and every
 * refusal is mapped onto the shared `code` / `message` / `request_id`
 * envelope.
 *
 * Not role-gated on the route, deliberately. A project owner is any
 * authenticated user, so a `role:` middleware would be both weaker (it would
 * not prove ownership) and wrong (it would exclude legitimate owners). The
 * ownership check is inside the service, which is the same precedent
 * ApplicationController's owner-side endpoints already set.
 */
class ProjectTeamController extends Controller
{
    public function __construct(
        private readonly ProjectTeamService $teamService,
    ) {}

    /**
     * POST /api/v1/projects/{project}/team/assignments
     */
    public function store(StoreTeamAssignmentRequest $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);

        $model = Project::query()->find($project);

        if (! $model) {
            return $this->errorResponse(
                'PROJECT_NOT_FOUND',
                'The requested project does not exist.',
                404,
                $requestId,
                ['project_id' => $project],
            );
        }

        try {
            $team = $this->teamService->assign(
                $model,
                $request->user(),
                $request->validated()['members'],
                $request->validated()['name'] ?? null,
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return (new ProjectTeamResource($team))
                ->additional([
                    'success' => true,
                    'message' => 'Team members assigned successfully.',
                    'request_id' => $requestId,
                ])
                ->response()
                ->setStatusCode(201)
                ->header('X-Request-ID', $requestId);
        } catch (ProjectException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id, 'project_id' => $project]);
        }
    }

    /**
     * GET /api/v1/projects/{project}/team
     *
     * The team screen's whole read: accepted applicants, who is staffed, the
     * waitlist, and how much of each role is still unfilled.
     */
    public function show(Request $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);

        $model = $this->resolveProject($project, $requestId);

        if ($model instanceof JsonResponse) {
            return $model;
        }

        try {
            $this->teamService->assertMayView($model, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Project team retrieved successfully.',
                'data' => $this->teamService->payload($model),
                'request_id' => $requestId,
            ], 200, ['X-Request-ID' => $requestId]);
        } catch (ProjectException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id, 'project_id' => $project]);
        }
    }

    /**
     * DELETE /api/v1/projects/{project}/team/assignments/{application}
     *
     * Addressed by the application, which is what the company chose the person
     * from — the frontend's participant rows carry that id, not a member id.
     */
    public function destroy(Request $request, int $project, int $application): JsonResponse
    {
        $requestId = $this->requestId($request);

        $model = $this->resolveProject($project, $requestId);

        if ($model instanceof JsonResponse) {
            return $model;
        }

        try {
            $team = $this->teamService->unassign(
                $model,
                $request->user(),
                $application,
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return (new ProjectTeamResource($team))
                ->additional([
                    'success' => true,
                    'message' => 'Team member removed successfully.',
                    'request_id' => $requestId,
                ])
                ->response()
                ->setStatusCode(200)
                ->header('X-Request-ID', $requestId);
        } catch (ProjectException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id, 'project_id' => $project]);
        }
    }

    /**
     * POST /api/v1/projects/{project}/team/invitations
     *
     * Invite every assigned member who has no pending invitation. Idempotent:
     * pressing it twice sends nothing the second time and answers `sent: 0`.
     */
    public function invite(Request $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);

        $model = $this->resolveProject($project, $requestId);

        if ($model instanceof JsonResponse) {
            return $model;
        }

        $message = $request->input('message');
        $message = is_string($message) && trim($message) !== '' ? trim($message) : null;

        if ($message !== null && mb_strlen($message) > 2000) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                'The request could not be processed.',
                422,
                $requestId,
                ['errors' => ['message' => ['The message may not be greater than 2000 characters.']]],
            );
        }

        try {
            $result = $this->teamService->invite(
                $model,
                $request->user(),
                $message,
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return response()->json([
                'success' => true,
                'message' => $result['sent'] === 0
                    ? 'Everyone on the team already has a pending invitation.'
                    : 'Invitations sent successfully.',
                'data' => $result,
                'request_id' => $requestId,
            ], 200, ['X-Request-ID' => $requestId]);
        } catch (ProjectException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id, 'project_id' => $project]);
        }
    }

    /**
     * Resolve {project} into the standard 404 envelope.
     */
    private function resolveProject(int $project, string $requestId): Project|JsonResponse
    {
        $model = Project::query()->find($project);

        if ($model !== null) {
            return $model;
        }

        return $this->errorResponse(
            'PROJECT_NOT_FOUND',
            'The requested project does not exist.',
            404,
            $requestId,
            ['project_id' => $project],
        );
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
    }

    private function unexpected(Throwable $e, string $requestId, array $context = []): JsonResponse
    {
        Log::error('Project team request failed.', array_merge([
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
