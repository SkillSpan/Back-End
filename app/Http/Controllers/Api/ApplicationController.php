<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApplicationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApplicationRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\Project;
use App\Services\Projects\ApplicationService;
use App\Support\SafeLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class ApplicationController extends Controller
{
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly ApplicationService $applicationService,
    ) {}

    public function store(StoreApplicationRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);
        $learner = $request->user();

        if ($learner->studentProfile === null) {
            return $this->errorResponse(
                'STUDENT_PROFILE_NOT_FOUND',
                'The authenticated learner does not have a student profile.',
                422,
                $requestId,
            );
        }

        $projectId = (int) $request->route('project');
        $project = Project::query()->find($projectId);

        if (! $project) {
            return $this->errorResponse(
                'PROJECT_NOT_FOUND',
                'The requested project does not exist.',
                404,
                $requestId,
                ['project_id' => $projectId],
            );
        }

        try {
            $application = $this->applicationService->submit(
                $project,
                $learner,
                $request->validated(),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            $created = $application->wasRecentlyCreated;

            return (new ApplicationResource($application->load(['project.organization:id,name', 'projectRole'])))
                ->additional([
                    'success' => true,
                    'message' => $created
                        ? 'Application submitted successfully.'
                        : 'Application already submitted; returning the existing application.',
                    'request_id' => $requestId,
                ])
                ->response()
                ->setStatusCode($created ? 201 : 200)
                ->header('X-Request-ID', $requestId);
        } catch (ApplicationException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $learner->id, 'project_id' => $projectId]);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $status = $request->query('status');
        $status = is_string($status) && $status !== '' ? $status : null;

        if ($status !== null && ! in_array($status, $this->allStatuses(), true)) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                'The request could not be processed.',
                422,
                $requestId,
                ['errors' => ['status' => ['The selected status is invalid.']]],
            );
        }

        $applications = $this->applicationService->forLearner(
            $request->user(),
            $status,
            $this->perPage($request),
        );

        return response()->json([
            'success' => true,
            'message' => 'Applications retrieved successfully.',
            'data' => ApplicationResource::collection($applications->items()),
            'meta' => $this->meta($applications),
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    public function indexForProject(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $projectId = (int) $request->route('project');
        $project = Project::query()->find($projectId);

        if (! $project) {
            return $this->errorResponse(
                'PROJECT_NOT_FOUND',
                'The requested project does not exist.',
                404,
                $requestId,
                ['project_id' => $projectId],
            );
        }

        try {
            $applications = $this->applicationService->forProject(
                $project,
                $request->user(),
                $this->perPage($request),
            );
        } catch (ApplicationException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }

        return response()->json([
            'success' => true,
            'message' => 'Project applications retrieved successfully.',
            'data' => ApplicationResource::collection($applications->items()),
            'meta' => $this->meta($applications),
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    public function withdraw(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $application = $this->resolveApplication($request);

        if ($application instanceof JsonResponse) {
            return $application;
        }

        $reason = $request->input('reason');
        $reason = is_string($reason) && trim($reason) !== '' ? trim($reason) : null;

        try {
            $application = $this->applicationService->withdraw(
                $application,
                $request->user(),
                $reason,
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (ApplicationException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }

        return (new ApplicationResource($application->load(['project.organization:id,name', 'projectRole'])))
            ->additional([
                'success' => true,
                'message' => 'Application withdrawn successfully.',
                'request_id' => $requestId,
            ])
            ->response()
            ->setStatusCode(200)
            ->header('X-Request-ID', $requestId);
    }

    public function decide(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $application = $this->resolveApplication($request);

        if ($application instanceof JsonResponse) {
            return $application;
        }

        $projectId = (int) $request->route('project');

        if ((int) $application->project_id !== $projectId) {
            return $this->errorResponse(
                'APPLICATION_PROJECT_MISMATCH',
                'The application does not belong to the specified project.',
                404,
                $requestId,
                ['application_id' => $application->id, 'project_id' => $projectId],
            );
        }

        return $this->applyDecision($request, $application, $requestId);
    }

    public function decideForApplication(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $application = $this->resolveApplication($request);

        if ($application instanceof JsonResponse) {
            return $application;
        }

        return $this->applyDecision($request, $application, $requestId);
    }

    private function applyDecision(Request $request, Application $application, string $requestId): JsonResponse
    {
        $validator = validator($request->all(), [
            'status' => ['required', 'string', Rule::in([
                Application::STATUS_SHORTLISTED,
                Application::STATUS_ACCEPTED,
                Application::STATUS_REJECTED,
                Application::STATUS_WAITLISTED,
            ])],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                'The request could not be processed.',
                422,
                $requestId,
                ['errors' => $validator->errors()->messages()],
            );
        }

        $data = $validator->validated();

        try {
            $application = $this->applicationService->decide(
                $application,
                $request->user(),
                $data['status'],
                $data['reason'] ?? null,
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (ApplicationException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }

        return (new ApplicationResource($application->load(['project.organization:id,name', 'projectRole'])))
            ->additional([
                'success' => true,
                'message' => 'Application status updated successfully.',
                'request_id' => $requestId,
            ])
            ->response()
            ->setStatusCode(200)
            ->header('X-Request-ID', $requestId);
    }

    public function show(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $application = $this->resolveApplication($request);

        if ($application instanceof JsonResponse) {
            return $application;
        }

        try {
            $application = $this->applicationService->forViewer($application, $request->user());
        } catch (ApplicationException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }

        return (new ApplicationResource(
            $application->load(['project.organization:id,name', 'projectRole', 'applicant:id,name'])
        ))
            ->additional([
                'success' => true,
                'message' => 'Application retrieved successfully.',
                'request_id' => $requestId,
            ])
            ->response()
            ->setStatusCode(200)
            ->header('X-Request-ID', $requestId);
    }

    private function resolveApplication(Request $request): Application|JsonResponse
    {
        $applicationId = (int) $request->route('application');

        $application = Application::query()->find($applicationId);

        if (! $application) {
            return $this->errorResponse(
                'APPLICATION_NOT_FOUND',
                'The requested application does not exist.',
                404,
                $this->requestId($request),
                ['application_id' => $applicationId],
            );
        }

        return $application;
    }

    private function perPage(Request $request): int
    {
        $requested = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);

        if ($requested < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($requested, self::MAX_PER_PAGE);
    }

    private function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    private function allStatuses(): array
    {
        return [
            Application::STATUS_SUBMITTED,
            Application::STATUS_SHORTLISTED,
            Application::STATUS_ACCEPTED,
            Application::STATUS_REJECTED,
            Application::STATUS_WAITLISTED,
            Application::STATUS_WITHDRAWN,
        ];
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
    }

    private function unexpected(Throwable $e, string $requestId, array $context = []): JsonResponse
    {
        Log::error('Project application request failed.', array_merge([
            'request_id' => $requestId,
            'failure_reason' => SafeLog::reason($e),
        ], $context));

        return $this->errorResponse(
            'APPLICATION_FAILED',
            'The application request could not be completed.',
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
