<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListCareerRolesRequest;
use App\Models\CareerRole;
use App\Models\Specialization;
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
     * List all approved career roles.
     *
     * Optional `?specialization_id={id}` narrows the list to the approved
     * roles linked to that specialization (via career_role_specialization).
     * The "Self-Learning / Free Track" specialization is the exception: it
     * carries no links and returns EVERY approved role, so a self-taught
     * learner is not restricted to one academic track.
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

        $specialization = $specializationId !== null
            ? Specialization::find($specializationId)
            : null;

        /*
         * `version` alone is not a total order. Most approved roles share
         * version 1, and with no tie-breaker the engine is free to return
         * equal rows in any order — so a role could appear on two pages or
         * be skipped entirely, which is exactly how an unstable sort breaks
         * a paginated list. Ordering by `id` descending as well makes the
         * order (and therefore the pagination) deterministic; the same rule
         * is used by the project catalog and the assistant's roadmap lookup.
         */
        $careerRoles = CareerRole::where('status', 'approved')
            ->withCount('roleSkills as skills_count')
            ->when(
                $specialization !== null && ! $specialization->isFreeTrack(),
                fn ($query) => $query->whereHas(
                    'specializations',
                    fn ($specializationQuery) => $specializationQuery->whereKey($specialization->id),
                ),
            )
            ->latest('version')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'success' => true,
            'message' => 'Career roles retrieved successfully.',
            'data' => $careerRoles,
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
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

        $careerRole = $this->findApprovedCareerRole($id, $requestId);

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
