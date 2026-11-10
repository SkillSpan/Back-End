<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Api\Company\Concerns\ResolvesCompanyOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CompanyProjectController extends Controller
{
    use ResolvesCompanyOrganization;

    private const PROJECT_WITH = [
        'organization:id,name',
        'requiredSkills.skill:id,name',
        'projectRoles',
        'eligibilityConstraints',
        'careerRole:id,title,slug,version',
    ];

    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 100;

    private const SORTS = ['recent', 'oldest', 'deadline', 'title'];

    public function index(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            return $this->organizationDenied($request, $requestId);
        }

        $query = Project::query()
            ->with(self::PROJECT_WITH)
            ->where('organization_id', $organization->id);

        $this->applyFilters($query, $request);

        $projects = $query->paginate($this->perPage($request))->withQueryString();

        return response()->json([
            'success' => true,
            'message' => 'Company projects retrieved successfully.',
            'data' => $this->decorate(
                ProjectResource::collection($projects->items())->response()->getData(true),
                $projects->getCollection(),
            )['data'],
            'meta' => [
                'current_page' => $projects->currentPage(),
                'last_page' => $projects->lastPage(),
                'per_page' => $projects->perPage(),
                'total' => $projects->total(),
            ],
            'stats' => $this->stats($organization),
            'filters' => $this->appliedFilters($request),
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    public function show(Request $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);

        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            return $this->organizationDenied($request, $requestId);
        }

        $model = Project::query()
            ->with(self::PROJECT_WITH)
            ->where('organization_id', $organization->id)
            ->find($project);

        if (! $model) {
            return $this->errorResponse(
                'PROJECT_NOT_FOUND',
                'The requested project does not exist.',
                404,
                $requestId,
                ['project_id' => $project],
            );
        }

        $payload = $this->decorate(
            (new ProjectResource($model))->response()->getData(true),
            [$model],
        );

        return response()->json([
            'success' => true,
            'message' => 'Project retrieved successfully.',
            'data' => $payload['data'],
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $search = trim((string) $request->query('q', ''));

        if ($search !== '') {
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('title', 'like', "%{$search}%")
                    ->orWhere('domain', 'like', "%{$search}%");

                if (ctype_digit($search)) {
                    $inner->orWhere('id', (int) $search);
                }
            });
        }

        $status = $request->query('status');

        if (is_string($status) && in_array($status, Project::STATUSES, true)) {
            $query->where('status', $status);
        }

        $type = $request->query('type');

        if (is_string($type) && in_array($type, ['simulation', 'company_sponsored'], true)) {
            $query->where('type', $type);
        }

        $careerRoleId = $request->query('career_role_id');

        if (is_numeric($careerRoleId)) {
            $query->where('career_role_id', (int) $careerRoleId);
        }

        match ((string) $request->query('sort', 'recent')) {
            'oldest' => $query->orderBy('id'),
            'deadline' => $query->orderByRaw('application_deadline IS NULL')->orderBy('application_deadline'),
            'title' => $query->orderBy('title'),
            default => $query->latest('id'),
        };
    }

    private function appliedFilters(Request $request): array
    {
        $sort = (string) $request->query('sort', 'recent');

        return array_filter([
            'q' => trim((string) $request->query('q', '')),
            'status' => $request->query('status'),
            'type' => $request->query('type'),
            'career_role_id' => $request->query('career_role_id'),
            'sort' => in_array($sort, self::SORTS, true) ? $sort : 'recent',
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function stats(Organization $organization): array
    {
        $rows = Project::query()
            ->where('organization_id', $organization->id)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $byStatus = array_fill_keys(Project::STATUSES, 0);

        foreach ($rows as $status => $count) {
            if (array_key_exists($status, $byStatus)) {
                $byStatus[$status] = (int) $count;
            }
        }

        return [
            'total' => array_sum($byStatus),
            'by_status' => $byStatus,
        ];
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);

        if ($perPage < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }

    private function decorate(array $payload, iterable $models): array
    {
        if (! isset($payload['data']) || ! is_array($payload['data'])) {
            return $payload;
        }

        $byId = [];

        foreach ($models as $model) {
            $byId[(int) $model->id] = $model;
        }

        $payload['data'] = array_is_list($payload['data'])
            ? array_map(fn ($row) => $this->decorateRow($row, $byId), $payload['data'])
            : $this->decorateRow($payload['data'], $byId);

        return $payload;
    }

    private function decorateRow($row, array $byId)
    {
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

    private function organizationDenied(Request $request, string $requestId): JsonResponse
    {
        $denial = $this->organizationDenial($request);

        return $this->errorResponse(
            $denial['code'],
            $denial['code'] === 'NO_ORGANIZATION'
                ? 'This account is not linked to any organization.'
                : 'Only an organization admin can view the company projects.',
            403,
            $requestId,
        );
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
    }

    private function errorResponse(
        string $code,
        string $message,
        int $status,
        string $requestId,
        array $details = [],
    ): JsonResponse {
        $payload = [
            'success' => false,
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
