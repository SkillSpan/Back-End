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
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 100;

    public function index(ListCareerRolesRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $specializationId = $request->validated('specialization_id');

        $search = trim((string) $request->validated('search', ''));

        $specialization = $specializationId !== null
            ? Specialization::find($specializationId)
            : null;

        $careerRoles = app(CareerRoleCatalogService::class)
            ->selectableQuery($specialization)
            ->withCount('roleSkills as skills_count')
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

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    private function perPage(Request $request): int
    {
        $requested = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);

        if ($requested < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($requested, self::MAX_PER_PAGE);
    }

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
