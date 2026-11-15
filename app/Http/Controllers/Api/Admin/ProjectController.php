<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ProjectException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\Projects\ProjectLifecycleService;
use App\Support\SafeLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProjectController extends Controller
{
    private const PROJECT_WITH = [
        'organization:id,name',
        'owner:id,name,email',
        'requiredSkills.skill:id,name',
        'projectRoles',
        'eligibilityConstraints',
        'careerRole:id,title,slug,version',
    ];

    public function __construct(
        private readonly ProjectLifecycleService $lifecycleService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $query = Project::query()->with(self::PROJECT_WITH)->latest('id');

        $this->applyFilters($query, $request);

        $perPage = $this->perPage($request);

        $projects = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'success' => true,
            'message' => 'Projects retrieved successfully.',
            'data' => $this->withAdminFields(
                ProjectResource::collection($projects)->response()->getData(true),
                $projects->getCollection(),
            ),
            'stats' => $this->stats(),
            'request_id' => $requestId,
            'filters' => $this->appliedFilters($request),
        ], 200, ['X-Request-ID' => $requestId]);
    }

    private function stats(): array
    {
        $byStatus = Project::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $count = fn (array $statuses): int => (int) array_sum(
            array_intersect_key($byStatus, array_flip($statuses))
        );

        return [
            'total' => (int) array_sum($byStatus),
            'draft' => $count([Project::STATUS_DRAFT]),
            'awaiting_review' => $count([
                Project::STATUS_SUBMITTED,
                Project::STATUS_CHANGES_REQUESTED,
            ]),
            'open' => $count([Project::STATUS_OPEN]),
        ];
    }

    public function show(Request $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);

        $model = Project::query()->with(self::PROJECT_WITH)->find($project);

        if (! $model) {
            return $this->projectNotFound($project, $requestId);
        }

        return response()->json([
            'success' => true,
            'message' => 'Project retrieved successfully.',
            'data' => $this->withAdminFields(
                (new ProjectResource($model))->response()->getData(true),
                $model,
            )['data'],
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

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

            return $this->projectResponse(
                $project->load(self::PROJECT_WITH),
                'Project created successfully.',
                201,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()?->id]);
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

            return $this->projectResponse(
                $updated->load(self::PROJECT_WITH),
                'Project updated successfully.',
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, [
                'user_id' => $request->user()?->id,
                'project_id' => $project,
            ]);
        }
    }

    public function cancel(Project $project, Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $cancelled = $this->lifecycleService->cancel(
                $project,
                $request->user(),
                $request->input('reason'),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->projectResponse($cancelled, 'Project cancelled.', 200, $requestId);
        } catch (ProjectException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, [
                'user_id' => $request->user()?->id,
                'project_id' => $project->id,
            ]);
        }
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $search = trim((string) $request->query('q', ''));

        if ($search !== '') {
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('title', 'like', "%{$search}%")
                    ->orWhere('domain', 'like', "%{$search}%")
                    ->orWhere('role', 'like', "%{$search}%");

                if (ctype_digit($search)) {
                    $inner->orWhere('id', (int) $search);
                }
            });
        }

        $type = $request->query('type');

        if (is_string($type) && in_array($type, ['simulation', 'company_sponsored'], true)) {
            $query->where('type', $type);
        }

        $status = $request->query('status');

        $allowedStatuses = array_merge(Project::STATUSES, [Project::STATUS_CLOSED]);

        if (is_string($status) && in_array($status, $allowedStatuses, true)) {
            $query->where('status', $status);
        }

        $difficulty = $request->query('difficulty');

        if (is_numeric($difficulty)) {
            $query->where('difficulty', (float) $difficulty);
        }

        $organizationId = $request->query('organization_id');

        if (is_numeric($organizationId)) {
            $query->where('organization_id', (int) $organizationId);
        }
    }

    private function appliedFilters(Request $request): array
    {
        return array_filter([
            'q' => trim((string) $request->query('q', '')),
            'type' => $request->query('type'),
            'status' => $request->query('status'),
            'difficulty' => $request->query('difficulty'),
            'organization_id' => $request->query('organization_id'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', 15);

        return max(1, min($perPage, 100));
    }

    private function projectResponse(Project $project, string $message, int $status, string $requestId): JsonResponse
    {
        $project->loadMissing(self::PROJECT_WITH);

        $payload = $this->withAdminFields(
            (new ProjectResource($project))->response()->getData(true),
            $project,
        );

        return response()->json(array_merge([
            'success' => true,
            'message' => $message,
        ], $payload, [
            'request_id' => $requestId,
        ]), $status, ['X-Request-ID' => $requestId]);
    }

    private function withAdminFields(array $payload, Project|iterable $models): array
    {
        $byId = [];

        foreach (is_iterable($models) && ! $models instanceof Project ? $models : [$models] as $model) {
            $byId[(int) $model->id] = $model;
        }

        if (! isset($payload['data']) || ! is_array($payload['data'])) {
            return $payload;
        }

        $payload['data'] = array_is_list($payload['data'])
            ? array_map(fn ($row) => $this->decorate($row, $byId), $payload['data'])
            : $this->decorate($payload['data'], $byId);

        return $payload;
    }

    private function decorate($row, array $byId)
    {
        $target = null;

        if (is_array($row) && isset($row['data']['id'])) {
            $target = &$row['data'];
        } elseif (is_array($row) && isset($row['id'])) {
            $target = &$row;
        } else {
            return $row;
        }

        $model = $byId[(int) $target['id']] ?? null;

        if ($model === null) {
            return $row;
        }

        $target['is_editable'] = $model->isEditable();

        $target['project_roles'] = $model->projectRoles
            ->values()
            ->map(fn ($role) => [
                'id' => $role->id,
                'title' => $role->title,
                'description' => $role->description,
                'is_active' => (bool) $role->is_active,
            ])
            ->all();

        $target['eligibility_constraints'] = $model->eligibilityConstraints
            ->values()
            ->map(fn ($constraint) => [
                'id' => $constraint->id,
                'constraint_type' => $constraint->constraint_type,
                'value' => $constraint->value,
            ])
            ->all();

        return $row;
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
        Log::error('Admin project request failed.', array_merge([
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
