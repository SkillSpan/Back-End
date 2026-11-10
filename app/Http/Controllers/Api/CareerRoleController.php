<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListCareerRolesRequest;
use App\Models\CareerRole;
use App\Models\Specialization;
use App\Services\CareerRoles\CareerRoleCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CareerRoleController extends Controller
{
    /*
     * BA-04 — the list pagination contract.
     *
     * The endpoint used to call `paginate(15)` with the page size hardcoded,
     * so `?per_page=N` was silently ignored. That made career-roles the only
     * list endpoint in the project that did not honour `per_page`, and the
     * SRS requires list endpoints to "enforce a configurable maximum page
     * size" (NFR-PERF-03, Must).
     *
     * The size is now read from the request and clamped — the same contract
     * ApplicationController and RecommendationController already use
     * (default 15, max 100). The default is unchanged, so a client that does
     * not send `per_page` (e.g. the current frontend, which reads Pattern C)
     * sees exactly the same 15-item pages as before.
     */
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 100;

    /**
     * GET /api/v1/career-roles
     * List every career role a learner may currently pick.
     *
     * WHAT CHANGED IN THE MVP
     * -----------------------
     * The list is now driven by ACTIVATION, not by the specialization pivot:
     * a role is returned when it is `approved` AND `is_active`. Every active
     * specialization therefore returns the SAME set of roles — the
     * specialization only decides the ORDER, with its closest roles first
     * (config/mvp.php). A role that is not on a specialization's priority list
     * is still returned, after the priority block.
     *
     * The "Self-Learning / Free Track" specialization has no priority list, so
     * it returns the same set in a neutral order — which is also what the
     * endpoint returns when `specialization_id` is omitted, so existing
     * consumers keep their previous list and ordering.
     *
     * Ordering is applied in the query, before pagination, and is total
     * (`priority rank`, then `version DESC`, then `id DESC`), so pages are
     * stable and no role is skipped or duplicated.
     *
     * Optional `?per_page={n}` sets the page size (default 15, clamped to
     * 1..100). Without it the original behaviour is unchanged, so existing
     * consumers keep working.
     */
    public function index(ListCareerRolesRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        // Present-and-valid, or null when the filter was omitted.
        $specializationId = $request->validated('specialization_id');

        // Same contract for the free-text filter: trimmed, and null when it was
        // omitted or is nothing but whitespace, so `?search=` keeps the original
        // unfiltered behaviour instead of returning an empty page.
        $search = trim((string) $request->validated('search', ''));

        $specialization = $specializationId !== null
            ? Specialization::find($specializationId)
            : null;

        /*
         * The catalogue service owns BOTH decisions — which roles are
         * selectable (approved + active) and in what order — so the controller
         * cannot drift from the policy the rest of the app uses.
         *
         * `withCount` is applied after the base query is built because it only
         * adds a correlated sub-select; it never affects the ordering, and the
         * paginator's count query drops the ORDER BY while keeping the
         * selectable filters.
         *
         * Server-side search stays as it was: applied to the WHOLE selectable
         * set before pagination, with LIKE wildcards escaped, so a role on a
         * later page is reachable and a literal `%` does not match everything.
         */
        $careerRoles = app(CareerRoleCatalogService::class)
            ->selectableQuery($specialization)
            ->withCount('roleSkills as skills_count')
            /*
             * The track a role belongs to lives in the `career_role_specialization`
             * pivot, and the response used to be the bare model — so the
             * payload carried no specialization at all and the frontend had
             * nothing to group by, putting every role under "other".
             *
             * Eager-loaded rather than appended per row: the paginator's
             * `count()` query is untouched by a relation load, so this stays
             * one extra query for the whole page instead of one per role.
             */
            ->with('specializations')
            ->when(
                $search !== '',
                fn ($query) => $query->where(
                    fn ($searchQuery) => $searchQuery
                        ->where('title', 'like', '%'.$this->escapeLike($search).'%')
                        ->orWhere('description', 'like', '%'.$this->escapeLike($search).'%'),
                ),
            )
            ->paginate($this->perPage($request));

        return response()->json([
            'success' => true,
            'message' => 'Career roles retrieved successfully.',
            'data' => $careerRoles,
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * Neutralise LIKE wildcards in user input.
     *
     * Without this, searching for `_` or `%` would match every row, because
     * both are wildcards inside a LIKE pattern. `\` itself has to be escaped
     * first, otherwise it would escape the escapes we add.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    /**
     * Resolve the requested page size.
     *
     * Mirrors ApplicationController::perPage(): an absent, non-numeric,
     * zero or negative `per_page` falls back to the default, and anything
     * above the maximum is clamped rather than rejected, so a client asking
     * for an unbounded page can never pull the whole table in one request.
     */
    private function perPage(Request $request): int
    {
        $requested = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);

        if ($requested < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($requested, self::MAX_PER_PAGE);
    }

    /**
     * GET /api/v1/career-roles/{id}
     * Retrieve a specific approved career role.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $requestId = $this->requestId($request);

        $careerRole = $this->findApprovedCareerRole($id, $requestId, with: ['specializations']);

        if ($careerRole instanceof JsonResponse) {
            return $careerRole;
        }

        return response()->json([
            'success' => true,
            'message' => 'Career role retrieved successfully.',
            'request_id' => $requestId,
            'data' => [
                'id' => $careerRole->id,
                'title' => $careerRole->title,
                'version' => $careerRole->version,
                'effective_date' => $careerRole->effective_date,
                'status' => $careerRole->status,
            ],
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * GET /api/v1/career-roles/{id}/skills
     *
     * The career role retrieval API's main deliverable: required skills,
     * importance weights, critical flags, and prerequisites for an approved
     * role version, packaged as a validated decision snapshot — a
     * point-in-time, self-contained payload other services (e.g. Readiness)
     * can rely on without re-deriving these facts themselves.
     */
    public function skills(Request $request, $id): JsonResponse
    {
        $requestId = $this->requestId($request);

        $careerRole = $this->findApprovedCareerRole($id, $requestId, with: [
            'roleSkills.skill',
            'roleSkills.prerequisites',
        ]);

        if ($careerRole instanceof JsonResponse) {
            return $careerRole;
        }

        if ($careerRole->roleSkills->isEmpty()) {
            return $this->errorResponse(
                'CAREER_ROLE_NO_SKILLS',
                'The selected career role has no required skills.',
                422,
                $requestId,
            );
        }

        $formattedSkills = $careerRole->roleSkills->map(function ($roleSkill) {
            return [
                'skill_id' => $roleSkill->skill_id,
                'skill_name' => $roleSkill->skill?->name,
                'slug' => $roleSkill->skill?->slug,
                'required_level' => (float) $roleSkill->required_level,
                'importance_weight' => (float) $roleSkill->importance_weight,
                'is_critical' => (bool) $roleSkill->is_critical,
                'prerequisites' => $roleSkill->prerequisites->map(fn ($skill) => [
                    'skill_id' => $skill->id,
                    'slug' => $skill->slug,
                    'name' => $skill->name,
                ])->values(),
            ];
        })->values();

        $weights = $formattedSkills->pluck('importance_weight');

        return response()->json([
            'success' => true,
            'message' => 'Career role decision snapshot prepared successfully.',
            'request_id' => $requestId,
            'data' => [
                'career_role' => [
                    'id' => $careerRole->id,
                    'title' => $careerRole->title,
                    'version' => $careerRole->version,
                    'effective_date' => $careerRole->effective_date,
                    'status' => $careerRole->status,
                ],
                'skills' => $formattedSkills,
                'statistics' => [
                    'total_skills' => $formattedSkills->count(),
                    'critical_skills_count' => $formattedSkills->where('is_critical', true)->count(),
                    'average_importance_weight' => $weights->isNotEmpty()
                        ? round($weights->avg(), 3)
                        : 0,
                ],
                'snapshot' => [
                    'is_valid' => true,
                    'generated_at' => now()->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * Shared lookup + ownership/permission validation for a single career
     * role: only approved roles are ever visible through this API, and
     * "not found" vs "exists but not approved" are reported distinctly
     * (matching ReadinessService's error codes) rather than a blanket 404.
     *
     * `is_active` is deliberately NOT part of this lookup. Deactivating a role
     * only removes it from the pickers — a learner who already selected it (or
     * a project that already targets it) must still be able to read the role
     * and its skill snapshot. Blocking new selections happens where a
     * selection is made (StartBaselineAssessmentRequest, UpdateProfileRequest),
     * never on a read.
     */
    private function findApprovedCareerRole(mixed $id, string $requestId, array $with = []): CareerRole|JsonResponse
    {
        $careerRole = CareerRole::query()
            ->whereKey($id)
            ->where('status', 'approved')
            ->when($with !== [], fn ($query) => $query->with($with))
            ->first();

        if ($careerRole) {
            return $careerRole;
        }

        $exists = CareerRole::whereKey($id)->exists();

        if ($exists) {
            return $this->errorResponse(
                'CAREER_ROLE_NOT_APPROVED',
                'The selected career role is not approved.',
                422,
                $requestId,
            );
        }

        return $this->errorResponse(
            'CAREER_ROLE_NOT_FOUND',
            'The selected career role does not exist.',
            404,
            $requestId,
            ['career_role_id' => $id],
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
