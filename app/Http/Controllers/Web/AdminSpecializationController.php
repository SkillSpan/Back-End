<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\SpecializationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCareerRoleRequest;
use App\Http\Requests\StoreSpecializationRequest;
use App\Http\Requests\UpdateSpecializationRequest;
use App\Models\Specialization;
use App\Services\Assessment\SpecializationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Session-authenticated endpoints behind the admin "Specializations" page.
 *
 * Like the projects and questions panels, these live under
 * `/admin/api/specializations/*` on the normal web session (the panel has a
 * cookie, not a bearer token). The route group carries
 * `auth` + `account.active` + `admin` — no middleware is weakened.
 *
 * All logic lives in SpecializationService; this class only shapes HTTP.
 */
class AdminSpecializationController extends Controller
{
    public function __construct(
        private readonly SpecializationService $service,
    ) {}

    /**
     * GET /admin/api/specializations
     */
    public function index(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $perPage = max(1, min((int) $request->query('per_page', 15), 100));
        $status = $request->query('status');

        $specializations = $this->service->paginate(
            trim((string) $request->query('q', '')),
            in_array($status, ['active', 'inactive'], true) ? $status : null,
            $perPage,
        );

        return response()->json([
            'success' => true,
            'message' => 'Specializations retrieved successfully.',
            'data' => [
                'data' => collect($specializations->items())
                    ->map(fn (Specialization $specialization) => $this->transform($specialization))
                    ->values()
                    ->all(),
                'current_page' => $specializations->currentPage(),
                'per_page' => $specializations->perPage(),
                'total' => $specializations->total(),
                'last_page' => $specializations->lastPage(),
                'from' => $specializations->firstItem(),
                'to' => $specializations->lastItem(),
            ],
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * POST /admin/api/specializations
     */
    public function store(StoreSpecializationRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $specialization = $this->service->create($request->validated());

            return $this->specializationResponse($specialization, 'Specialization created successfully.', 201, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    /**
     * PATCH /admin/api/specializations/{specialization}
     */
    public function update(UpdateSpecializationRequest $request, Specialization $specialization): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $updated = $this->service->update($specialization, $request->validated());

            return $this->specializationResponse($updated, 'Specialization updated successfully.', 200, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    /**
     * POST /admin/api/specializations/{specialization}/activate
     */
    public function activate(Request $request, Specialization $specialization): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $updated = $this->service->setActive($specialization, true);

            return $this->specializationResponse($updated, 'Specialization activated.', 200, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    /**
     * POST /admin/api/specializations/{specialization}/deactivate
     *
     * The safe alternative to deleting a specialization that is still in
     * use: it disappears from the learner-facing reference list without
     * losing its career-role links.
     */
    public function deactivate(Request $request, Specialization $specialization): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $updated = $this->service->setActive($specialization, false);

            return $this->specializationResponse($updated, 'Specialization deactivated.', 200, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    /**
     * DELETE /admin/api/specializations/{specialization}
     *
     * Refused with a 409 when the specialization is still in use.
     */
    public function destroy(Request $request, Specialization $specialization): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $this->service->delete($specialization);

            return response()->json([
                'success' => true,
                'message' => 'Specialization deleted successfully.',
                'data' => ['id' => (int) $specialization->id],
                'request_id' => $requestId,
            ], 200, ['X-Request-ID' => $requestId]);
        } catch (SpecializationException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    /**
     * GET /admin/api/specializations/{specialization}/career-roles
     *
     * Every career role with an `attached` flag, for the manage modal.
     */
    public function careerRoles(Request $request, Specialization $specialization): JsonResponse
    {
        $requestId = $this->requestId($request);

        return response()->json([
            'success' => true,
            'message' => 'Career roles retrieved successfully.',
            'data' => $this->service->roleOptions(
                $specialization,
                trim((string) $request->query('q', '')),
            ),
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * PUT /admin/api/specializations/{specialization}/career-roles
     *
     * Replaces the linked set. Refused for the free track, whose roles are
     * resolved from its flag rather than from the pivot.
     */
    public function syncCareerRoles(Request $request, Specialization $specialization): JsonResponse
    {
        $requestId = $this->requestId($request);

        if ($specialization->isFreeTrack()) {
            return $this->errorResponse(
                'SPECIALIZATION_FREE_TRACK_PROTECTED',
                'The Self-Learning / Free Track already offers every career role and does not use a fixed list.',
                409,
                $requestId,
            );
        }

        $validated = $request->validate([
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['integer', 'exists:career_roles,id'],
        ]);

        try {
            $updated = $this->service->syncCareerRoles($specialization, $validated['role_ids']);

            return $this->specializationResponse($updated, 'Career roles updated successfully.', 200, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    /**
     * GET /admin/api/specializations/skills
     *
     * Skill options for the "create career role" form.
     */
    public function skills(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        return response()->json([
            'success' => true,
            'message' => 'Skills retrieved successfully.',
            'data' => $this->service->skillOptions(trim((string) $request->query('q', ''))),
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * POST /admin/api/specializations/career-roles
     */
    public function storeCareerRole(StoreCareerRoleRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $role = $this->service->createCareerRole(
                (string) $request->validated('title'),
                $request->validated('skill_ids', []),
            );

            return response()->json([
                'success' => true,
                'message' => 'Career role created successfully.',
                'data' => [
                    'id' => (int) $role->id,
                    'title' => (string) $role->title,
                    'slug' => (string) $role->slug,
                    'status' => (string) $role->status,
                    'skills_count' => $role->roleSkills()->count(),
                ],
                'request_id' => $requestId,
            ], 201, ['X-Request-ID' => $requestId]);
        } catch (SpecializationException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    // -----------------------------------------------------------------
    // Response helpers
    // -----------------------------------------------------------------

    private function specializationResponse(
        Specialization $specialization,
        string $message,
        int $status,
        string $requestId,
    ): JsonResponse {
        $specialization->loadCount('careerRoles');

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $this->transform($specialization),
            'request_id' => $requestId,
        ], $status, ['X-Request-ID' => $requestId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(Specialization $specialization): array
    {
        return [
            'id' => (int) $specialization->id,
            'name' => (string) $specialization->name,
            'description' => $specialization->description,
            'is_active' => (bool) $specialization->is_active,
            'is_free_track' => $specialization->isFreeTrack(),
            'career_roles_count' => (int) ($specialization->career_roles_count ?? 0),
        ];
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
    }

    private function unexpected(Throwable $e, string $requestId): JsonResponse
    {
        Log::error('Admin specialization request failed.', [
            'request_id' => $requestId,
            'failure_reason' => $e->getMessage(),
        ]);

        return $this->errorResponse(
            'SPECIALIZATION_REQUEST_FAILED',
            'The specialization request could not be completed.',
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
