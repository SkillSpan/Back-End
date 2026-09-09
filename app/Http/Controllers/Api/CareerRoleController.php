<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CareerRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CareerRoleController extends Controller
{
    /**
     * GET /api/v1/career-roles
     * List all approved career roles.
     */
    public function index(Request $request): JsonResponse
    {
        $careerRoles = CareerRole::where('status', 'approved')
            ->withCount('roleSkills as skills_count')
            ->latest('version')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'message' => 'Career roles retrieved successfully.',
            'data' => $careerRoles,
        ]);
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
            'data' => [
                'id' => $careerRole->id,
                'title' => $careerRole->title,
                'version' => $careerRole->version,
                'effective_date' => $careerRole->effective_date,
                'status' => $careerRole->status,
            ],
        ]);
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
