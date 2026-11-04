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

/**
 * The company's own projects — list and detail.
 *
 * WHY THIS EXISTS
 * ---------------
 * Until now a company could create a project and drive it through the
 * lifecycle, but could never read one back:
 *
 *   - `GET /api/v1/projects` and `GET /api/v1/projects/{project}` are
 *     `role:learner` — a company admin gets 403 on its OWN project.
 *   - `GET /api/v1/company/dashboard` returns per-status COUNTS and the last
 *     five projects, not a list.
 *
 * So there was no endpoint behind a "My projects" screen, and no way to open a
 * project after creating it. This controller is that missing read surface.
 *
 * SCOPING
 * -------
 * The organization comes from the caller's own admin membership (the same
 * `ResolvesCompanyOrganization` trait the dashboard and profile use), never
 * from a request parameter. Every query is `where organization_id = <that>`.
 * A project belonging to another organization is reported as **404**, not 403:
 * a 403 would confirm the id exists and turn the route into an existence
 * oracle.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * No writes. Status changes stay in ProjectLifecycleService so there is still
 * exactly one writer of `status`, one place that bumps `version` and one audit
 * trail. This class only reads.
 */
class CompanyProjectController extends Controller
{
    use ResolvesCompanyOrganization;

    /**
     * Relations the list and detail responses read.
     *
     * `organization:id,name` uses `name` because that is the real column — an
     * eager load naming a column that does not exist is skipped silently by
     * Laravel, which would surface as a missing organization rather than an
     * error.
     */
    private const PROJECT_WITH = [
        'organization:id,name',
        'requiredSkills.skill:id,name',
        'projectRoles',
        'eligibilityConstraints',
        'careerRole:id,title,slug,version',
    ];

    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 100;

    /** Sort keys the client may ask for. Anything else is ignored, not guessed. */
    private const SORTS = ['recent', 'oldest', 'deadline', 'title'];

    /**
     * GET /api/v1/company/projects
     */
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
                // `items()`, not the paginator: passing the paginator makes the
                // resource emit its own `links`/`meta` block nested under
                // `data`, while this API returns `meta` as a sibling. The page
                // numbers below are the contract, so the resource must not
                // invent a second one.
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

    /**
     * GET /api/v1/company/projects/{project}
     */
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

        // 404 — never 403. See the class docblock.
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

    // -----------------------------------------------------------------
    // Filters
    // -----------------------------------------------------------------

    /**
     * Search + filters a company needs on its own project list.
     *
     * An unknown `status` or `sort` is IGNORED rather than rejected: this is a
     * read screen with a filter bar, and a 422 for a stale bookmarked query is
     * worse than a slightly wider list. `type` is restricted to the two real
     * values so it cannot be used to probe the column.
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        $search = trim((string) $request->query('q', ''));

        if ($search !== '') {
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('title', 'like', "%{$search}%")
                    ->orWhere('domain', 'like', "%{$search}%");

                // `orWhereKey` does not exist on the builder, so the primary
                // key is matched explicitly and only when the term is numeric.
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

    /**
     * @return array<string, mixed>
     */
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

    /**
     * Per-status counts for THIS organization, computed over the whole
     * organization rather than the current page — a card reading "Draft: 2"
     * while fifty drafts exist would be worse than no card. Deliberately
     * independent of the active filters so the totals stay a stable reference
     * point while the operator narrows the list.
     *
     * Every one of the twelve statuses is present even at zero, so the UI can
     * render a stable grid instead of filling gaps itself. The legacy `closed`
     * value is not part of the approved dictionary and is not reported.
     *
     * @return array{total: int, by_status: array<string, int>}
     */
    private function stats(Organization $organization): array
    {
        // One grouped query, not twelve counts.
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

    // -----------------------------------------------------------------
    // Response helpers
    // -----------------------------------------------------------------

    /**
     * Add the three fields the company editor needs but that must not enter
     * the shared ProjectResource (the learner catalog and the recommendation
     * payload use the same resource, so nothing operator-specific belongs in
     * it):
     *
     *   - `is_editable` — read from Project::EDITABLE_STATUSES via the model,
     *     so the Edit button can never disagree with what PATCH accepts.
     *   - `project_roles` — EVERY role, including deactivated ones;
     *     `available_project_roles` is the learner-facing list and correctly
     *     hides inactive roles, but an editor must see what is stored.
     *   - `eligibility_constraints` — the STORED rows, as opposed to
     *     `eligibility`, which is the per-learner verdict and is null here.
     *
     * @param  array<string, mixed>  $payload
     * @param  iterable<Project>  $models
     * @return array<string, mixed>
     */
    private function decorate(array $payload, iterable $models): array
    {
        if (! isset($payload['data']) || ! is_array($payload['data'])) {
            return $payload;
        }

        $byId = [];

        foreach ($models as $model) {
            $byId[(int) $model->id] = $model;
        }

        // A collection's `data` is a list; a single resource's `data` is the
        // row itself. Both wrap each row as {data: {...}}.
        $payload['data'] = array_is_list($payload['data'])
            ? array_map(fn ($row) => $this->decorateRow($row, $byId), $payload['data'])
            : $this->decorateRow($payload['data'], $byId);

        return $payload;
    }

    /**
     * @param  array<int, Project>  $byId
     * @param  mixed  $row
     * @return mixed
     */
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

    /**
     * Same two 403 codes the dashboard and the profile use, so the frontend
     * branches on one vocabulary: `NO_ORGANIZATION` sends the user to
     * onboarding, `NOT_ORGANIZATION_ADMIN` to a "you are not an admin" screen.
     */
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
