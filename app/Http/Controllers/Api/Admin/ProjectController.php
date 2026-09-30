<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ProjectException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\Projects\ProjectLifecycleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Admin project management — the platform-administrator side of the project
 * workflow.
 *
 * WHY THIS EXISTS
 * ---------------
 * ProjectManagementController serves the OWNER side and is deliberately
 * owner-scoped: `assertMayManage` refuses anything the actor does not own, so
 * there is no endpoint that can list the projects still sitting in `draft`,
 * `submitted` or `rejected`. The learner-facing ProjectController is not a
 * substitute either — it requires a student profile and only ever returns the
 * projects a learner is eligible to see. Neither can back an admin review
 * screen, so this controller adds the read + moderation surface without
 * duplicating a single line of lifecycle logic.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * Every state change goes through ProjectLifecycleService, which stays the
 * only writer: it re-checks the actor, the allowed transition, completeness
 * and date ordering. This class never touches `status` directly, never bumps
 * `version`, and never re-implements an authorization rule — it is a thin
 * adapter exactly like Admin\OrganizationController.
 *
 * Responses reuse ProjectResource, so the project contract does not fork
 * between the owner API, the learner API and the admin panel.
 */
class ProjectController extends Controller
{
    /**
     * Relations the admin screen reads on both list and detail responses.
     *
     * `organization:id,name` uses `name` because that is the real column —
     * an eager load naming a column that does not exist is skipped silently
     * by Laravel, which would surface as a missing organization in the UI
     * rather than an error.
     */
    private const PROJECT_WITH = [
        'organization:id,name',
        'owner:id,name,email',
        'requiredSkills.skill:id,name',
        'projectRoles',
        'eligibilityConstraints',
    ];

    public function __construct(
        private readonly ProjectLifecycleService $lifecycleService,
    ) {}

    /**
     * GET /api/v1/admin/projects
     *
     * Every project in the system, newest first, with optional search and
     * filters. Server-side pagination so the panel never pulls the whole
     * table.
     */
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

    /**
     * Counts for the panel's summary cards.
     *
     * Computed over the whole table rather than the current page, because a
     * card reading "Draft: 2" while fifty drafts exist is worse than no card
     * at all. Deliberately independent of the active filters, so the totals
     * stay a stable reference point while an operator narrows the list.
     *
     * @return array<string, int>
     */
    private function stats(): array
    {
        // One grouped query rather than four counts.
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

    /**
     * GET /api/v1/admin/projects/{project}
     *
     * One project with the relations the detail screen renders.
     */
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

    /**
     * POST /api/v1/admin/projects
     *
     * Create a project from the admin panel.
     *
     * The form request and the lifecycle service are the SAME ones the owner
     * API uses (`POST /api/v1/projects`), deliberately: validation rules,
     * ownership resolution and the "starts as draft" rule must not fork just
     * because the form that submitted them was rendered by an administrator.
     * The service re-checks `assertMayCreate`, and a platform administrator
     * passes it, so an administrator creating here is authorized the same way
     * an administrator creating through the owner API would be.
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

    /**
     * PATCH /api/v1/admin/projects/{project}
     *
     * Edit a project from the admin panel.
     *
     * `UpdateProjectRequest` makes every top-level field optional, so this is
     * a partial update. Two rules are NOT relaxed and remain the service's:
     * the actor must be allowed to manage the project, and the project must be
     * in an editable status (`draft` or `changes_requested`). An approved or
     * open project is therefore refused with PROJECT_NOT_EDITABLE rather than
     * edited behind the review that approved it.
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

    /**
     * POST /api/v1/admin/projects/{project}/cancel
     *
     * The soft delete. `Project::TRANSITIONS` gives no state an edge into
     * `cancelled`, and the lifecycle deliberately exposes no delete endpoint,
     * because a project is referenced by applications, recommendations and a
     * versioned matching snapshot — removing the row would orphan all of it.
     * Retiring a project therefore means marking it `cancelled`, which
     * preserves the audit trail and every historical decision.
     *
     * This is intentionally NOT a general status editor: it accepts no target
     * status from the client, so the only reachable transition is the one
     * defined here. A project that has already been cancelled or archived is
     * reported as such rather than silently re-cancelled.
     */
    public function cancel(Project $project, Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            // The service owns the rules: authorized actor, and refuse a
            // project that has already reached a final state. Not duplicated
            // here so there is exactly one place that decides cancellability.
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

    // -----------------------------------------------------------------
    // Filters
    // -----------------------------------------------------------------

    /**
     * Search + filters a platform administrator needs when reviewing.
     *
     * `q` searches the fields an administrator actually identifies a project
     * by (title, domain, role) and accepts a numeric id so pasting an id from
     * a report works. Title matching is case-insensitive through LIKE, which
     * behaves identically on SQLite and MySQL/MariaDB for these collations.
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        $search = trim((string) $request->query('q', ''));

        if ($search !== '') {
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('title', 'like', "%{$search}%")
                    ->orWhere('domain', 'like', "%{$search}%")
                    ->orWhere('role', 'like', "%{$search}%");

                // There is no orWhereKey() on the query builder - only the
                // non-negated whereKey() - so the primary key is matched
                // explicitly. Guarded by ctype_digit so the column is never
                // compared against a non-numeric string.
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

        // `closed` is accepted here purely so a legacy row stays findable; it
        // has no transition of its own, per Project::STATUS_CLOSED.
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

    /**
     * The filters that were actually applied, echoed back so the panel can
     * keep its controls in sync with the response it is rendering.
     */
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

    // -----------------------------------------------------------------
    // Response helpers — same envelope as ProjectManagementController
    // -----------------------------------------------------------------

    private function projectResponse(Project $project, string $message, int $status, string $requestId): JsonResponse
    {
        // Same shape as `show`, so a create/update response is immediately
        // usable by the panel without a follow-up fetch.
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

    /**
     * Add the fields only the admin panel needs, per response.
     *
     * `ProjectResource` is shared by the learner catalog, the recommendation
     * payload and the owner API, so nothing operator-specific belongs in it.
     * These three are added here instead, which keeps every existing contract
     * byte-identical while still giving the panel what it needs:
     *
     *   - `is_editable` — read from Project::EDITABLE_STATUSES via the model,
     *     so the Edit button can never disagree with what `update` accepts.
     *   - `project_roles` — EVERY role, including deactivated ones.
     *     `available_project_roles` is the learner-facing list and correctly
     *     hides inactive roles; an editor must see what is stored, or an
     *     inactive role would vanish from the form and be dropped on save.
     *   - `eligibility_constraints` — the STORED rows, as opposed to
     *     `eligibility`, which is the per-learner verdict and is null here.
     *
     * The models are passed alongside the payload so the fields can be read
     * from Eloquent rather than re-derived from the JSON.
     *
     * @param  array<string, mixed>  $payload  the `getData(true)` array of a ProjectResource
     * @param  Project|iterable<Project>  $models
     * @return array<string, mixed>
     */
    private function withAdminFields(array $payload, Project|iterable $models): array
    {
        $byId = [];

        foreach (is_iterable($models) && ! $models instanceof Project ? $models : [$models] as $model) {
            $byId[(int) $model->id] = $model;
        }

        if (! isset($payload['data']) || ! is_array($payload['data'])) {
            return $payload;
        }

        // A collection's `data` is a list; a single resource's `data` is the
        // row itself. Both wrap each row as {data: {...}}.
        $payload['data'] = array_is_list($payload['data'])
            ? array_map(fn ($row) => $this->decorate($row, $byId), $payload['data'])
            : $this->decorate($payload['data'], $byId);

        return $payload;
    }

    /**
     * @param  array<int, Project>  $byId
     * @param  mixed  $row
     * @return mixed
     */
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
            'failure_reason' => $e->getMessage(),
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
