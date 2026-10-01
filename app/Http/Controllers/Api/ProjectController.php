<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\Projects\ProjectAccessService;
use App\Services\Projects\ProjectAvailabilityService;
use App\Services\Projects\ProjectCapacityPolicy;
use App\Services\Projects\ProjectEligibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    private const PROJECT_WITH = [
        'organization:id,name',
        'requiredSkills.skill:id,name',
        'eligibilityConstraints',
        // US-MATCH-02 — the roles a learner may select when applying, surfaced
        // as `available_project_roles` on the details response.
        'projectRoles',
    ];

    public function __construct(
        private readonly ProjectAccessService $accessService = new ProjectAccessService,
        private readonly ProjectAvailabilityService $availabilityService = new ProjectAvailabilityService,
        private readonly ProjectEligibilityService $eligibilityService = new ProjectEligibilityService,
        private readonly ProjectCapacityPolicy $capacityPolicy = new ProjectCapacityPolicy,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $requestId = (string) ($request->header('X-Request-ID') ?: Str::uuid());

        $user = $request->user();
        $studentProfile = $user->studentProfile;

        if (! $studentProfile) {
            return $this->errorResponse(
                'STUDENT_PROFILE_NOT_FOUND',
                'The authenticated learner does not have a student profile.',
                422,
                $requestId,
            );
        }

        $organizationIds = $this->accessService->activeOrganizationIds($user);

        $query = $this->accessibleProjectsQuery($organizationIds, self::PROJECT_WITH);

        /** @var array<string, mixed> $filters */
        $filters = $this->validatedFilters($request, $requestId);

        if ($filters instanceof JsonResponse) {
            return $filters;
        }

        // Keyword search across the visible project text fields.
        if (! empty($filters['search'])) {
            $like = $this->likePattern($filters['search']);

            $query->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('objectives', 'like', $like);
            });
        }

        // Exact-match filters on columns that exist in the current schema.
        foreach (['type', 'domain', 'work_mode'] as $column) {
            if (array_key_exists($column, $filters) && $filters[$column] !== null) {
                $query->where($column, $filters[$column]);
            }
        }

        if (array_key_exists('difficulty', $filters) && $filters['difficulty'] !== null) {
            $query->where('difficulty', $filters['difficulty']);
        }

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        // Role filter — "show me the projects for a Frontend Developer".
        //
        // A project advertises a role in TWO places, and they are not
        // interchangeable:
        //   - `projects.role` is the single headline role, a free-text column;
        //   - `project_roles` is the set of roles a learner may actually apply
        //     as (surfaced as `available_project_roles`), and a role that has
        //     been deactivated is NOT something a learner can apply to.
        //
        // Matching only one of them would silently hide projects the learner
        // can legitimately see: a project whose headline is "Data Analyst" but
        // which also offers an active "Data Analyst" role would be found by
        // neither test alone in the reverse case. So the filter matches EITHER,
        // and the `project_roles` branch requires `is_active` for the same
        // reason the resource filters on it.
        //
        // This is deliberately a FILTER, not a hard visibility rule: a learner
        // who supplies no `role` still sees the whole catalog (including
        // projects outside their specialty), which is what keeps discovery,
        // details and the recommendation list agreeing with each other.
        if (! empty($filters['role'])) {
            $role = $filters['role'];

            $query->where(function ($q) use ($role) {
                $q->where('role', $role)
                    ->orWhereHas('projectRoles', function ($roleQuery) use ($role) {
                        $roleQuery->where('title', $role)->where('is_active', true);
                    });
            });
        }

        // Required-skill filters: the project must require ALL of the
        // supplied skill ids (joined through the pivot table).
        if (! empty($filters['skill_ids'])) {
            $skillIds = $filters['skill_ids'];

            $query->whereHas('requiredSkills', function ($q) use ($skillIds) {
                $q->whereIn('skill_id', $skillIds);
            }, '=', count($skillIds));

            if (array_key_exists('minimum_level', $filters) && $filters['minimum_level'] !== null) {
                $query->whereHas('requiredSkills', function ($q) use ($skillIds, $filters) {
                    $q->whereIn('skill_id', $skillIds)
                        ->where('minimum_level', '>=', $filters['minimum_level']);
                }, '=', count($skillIds));
            }
        }

        // `id` is a tie-breaker: `created_at` has second precision, so projects
        // created in the same second would otherwise come back in an arbitrary
        // order and could be duplicated across pages or skipped entirely. The
        // deterministic secondary sort is what makes pagination correct.
        $projects = $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            'success' => true,
            'message' => 'Accessible projects retrieved successfully.',
            'data' => ProjectResource::collection($projects->items()),
            'meta' => [
                'current_page' => $projects->currentPage(),
                'last_page' => $projects->lastPage(),
                'per_page' => $projects->perPage(),
                'total' => $projects->total(),
            ],
            'request_id' => $requestId,
        ]);
    }

    /**
     * GET /api/v1/projects/{project}
     *
     * Returns the details of one project the authenticated learner is
     * authorized to see. The SAME access/availability rules as the
     * catalog are applied here (never weaker), so a learner cannot
     * bypass catalog restrictions by supplying a project ID directly.
     */
    public function show(Request $request, int $project): JsonResponse
    {
        $requestId = (string) ($request->header('X-Request-ID') ?: Str::uuid());

        $user = $request->user();
        $studentProfile = $user->studentProfile;

        if (! $studentProfile) {
            return $this->errorResponse(
                'STUDENT_PROFILE_NOT_FOUND',
                'The authenticated learner does not have a student profile.',
                422,
                $requestId,
            );
        }

        $accessibleProject = $this->getSecureAccessibleProject($project, $user);

        if (! $accessibleProject) {
            $exists = Project::query()->whereKey($project)->exists();

            return $this->errorResponse(
                $exists
                    ? 'PROJECT_UNAUTHORIZED'
                    : 'PROJECT_NOT_FOUND',
                $exists
                    ? 'The requested project is not available to this learner.'
                    : 'The requested project does not exist.',
                $exists ? 403 : 404,
                $requestId,
                ['project_id' => $project],
            );
        }

        // US-MATCH-02 — per-learner details. Both blocks are produced by
        // EXISTING services (eligibility + the capacity policy); neither rule is
        // re-implemented here. They are attached only on the details endpoint,
        // so the catalog list does not run one eligibility check per project.
        $resource = new ProjectResource($accessibleProject);

        $eligibility = $this->eligibilityService->check($accessibleProject, $user);

        $resource->eligibility = [
            'eligible' => $eligibility->eligible,
            'reasons' => $eligibility->reasons,
            'skill_failures' => $eligibility->skill_failures,
        ];

        $capacity = $this->capacityPolicy->check($accessibleProject);

        $resource->capacityState = [
            'capacity' => $capacity->capacity,
            'seats_taken' => $capacity->seats_taken,
            'seats_remaining' => $capacity->seats_remaining,
            'full' => $capacity->full,
        ];

        return response()->json([
            'success' => true,
            'message' => 'Project details retrieved successfully.',
            'data' => $resource,
            'request_id' => $requestId,
        ]);
    }

    /**
     * Retrieve a project that the authenticated learner is authorized to
     * view AND that satisfies availability rules. The query enforces the
     * same catalog-level restrictions (status, deadline, end_date,
     * confidentiality) so a learner cannot bypass access by supplying
     * a project ID directly.
     */
    private function getSecureAccessibleProject(int $projectId, $user): ?Project
    {
        $organizationIds = $this->accessService->activeOrganizationIds($user);

        $project = $this->accessibleProjectsQuery($organizationIds, self::PROJECT_WITH)
            ->whereKey($projectId)
            ->first();

        if ($project && ! $this->availabilityService->isAvailable($project)) {
            return null;
        }

        return $project;
    }

    /**
     * Reusable base query enforcing the project catalog access rules.
     *
     * Delegates to ProjectAccessService so that discovery, project details and
     * the recommendation list can never drift apart. The membership lookup
     * that used to live here ignored `organization_members.status`, letting a
     * removed or merely invited member see an organization's restricted
     * projects — see ProjectAccessService for the full note.
     *
     * @param  list<int>  $organizationIds  the learner's ACTIVE memberships
     * @param  array<int, string>  $with
     */
    private function accessibleProjectsQuery(array $organizationIds, array $with = []): Builder
    {
        return $this->accessService->accessibleProjectsQuery($organizationIds, $with);
    }

    private function likePattern(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($term)).'%';
    }

    /**
     * Validate the supported project-catalog filter query parameters.
     * Returns the validated array on success, or a JsonResponse with the
     * standard project VALIDATION_ERROR contract on failure.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function validatedFilters(Request $request, string $requestId): array|JsonResponse
    {
        $validator = validator($request->query(), [
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'in:simulation,company_sponsored'],
            'domain' => ['nullable', 'string', 'max:100'],
            'work_mode' => ['nullable', 'string', 'max:50'],
            // Free text on purpose: `projects.role` is a free-text column and
            // `project_roles.title` is free text too, so an `in:` whitelist
            // would reject role titles that legitimately exist. An unmatched
            // value simply returns an empty page, exactly like `domain`.
            'role' => ['nullable', 'string', 'max:100'],
            'difficulty' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'organization_id' => ['nullable', 'integer', 'gt:0', 'exists:organizations,id'],
            'skill_ids' => ['nullable', 'array'],
            'skill_ids.*' => ['integer', 'gt:0', 'exists:skills,id'],
            'minimum_level' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
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

        $filters = $validator->validated();

        // `minimum_level` is the per-skill floor for the required-skills filter,
        // so it is meaningless without `skill_ids`. It used to be silently
        // ignored, so a caller could believe a filter was applied and receive
        // unfiltered results. Reject it explicitly instead.
        $hasMinimumLevel = array_key_exists('minimum_level', $filters) && $filters['minimum_level'] !== null;
        $hasSkillIds = ! empty($filters['skill_ids']);

        if ($hasMinimumLevel && ! $hasSkillIds) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                'The request could not be processed.',
                422,
                $requestId,
                ['errors' => ['minimum_level' => ['The minimum_level filter requires skill_ids.']]],
            );
        }

        // `skill_ids` is a SET of required skills. Duplicates used to break the
        // "requires ALL of these skills" count: the whereHas operator used
        // count($skillIds) while the pivot can only match distinct skills, so
        // `skill_ids[]=1&skill_ids[]=1` returned no projects at all. Collapse
        // to distinct ids so a repeated id simply means "skill 1".
        if ($hasSkillIds) {
            $filters['skill_ids'] = array_values(array_unique(array_map('intval', $filters['skill_ids'])));
        }

        return $filters;
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
