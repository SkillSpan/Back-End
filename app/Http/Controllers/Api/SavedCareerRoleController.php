<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CareerRole;
use App\Services\CareerRoles\SavedCareerRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SavedCareerRoleController extends Controller
{
    public function __construct(private readonly SavedCareerRoleService $saved) {}

    public function index(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $roles = $this->saved
            ->list($request->user())
            ->map(fn ($saved) => $this->saved->payload($saved))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Saved career roles retrieved successfully.',
            'data' => $roles,
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $requestId = $this->requestId($request);

        $careerRole = $this->findSelectableRole($id, $requestId);

        if ($careerRole instanceof JsonResponse) {
            return $careerRole;
        }

        $saved = $this->saved->save($request->user(), $careerRole);

        return response()->json([
            'success' => true,
            'message' => 'Career role saved.',
            'data' => [
                'career_role_id' => $careerRole->id,
                'is_saved' => true,
                'saved_at' => $saved->created_at?->toIso8601String(),
            ],
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $requestId = $this->requestId($request);

        $careerRole = $this->findSelectableRole($id, $requestId);

        if ($careerRole instanceof JsonResponse) {
            return $careerRole;
        }

        $this->saved->remove($request->user(), $careerRole);

        return response()->json([
            'success' => true,
            'message' => 'Career role removed from saved roles.',
            'data' => [
                'career_role_id' => $careerRole->id,
                'is_saved' => false,
            ],
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    private function findSelectableRole(int $id, string $requestId): CareerRole|JsonResponse
    {
        $careerRole = CareerRole::query()->whereKey($id)->first();

        if ($careerRole === null) {
            return $this->errorResponse(
                'CAREER_ROLE_NOT_FOUND',
                'The selected career role does not exist.',
                404,
                $requestId,
                ['career_role_id' => $id],
            );
        }

        if (! $careerRole->isSelectable()) {
            return $this->errorResponse(
                'CAREER_ROLE_NOT_APPROVED',
                'The selected career role is not available.',
                422,
                $requestId,
                ['career_role_id' => $id],
            );
        }

        return $careerRole;
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
        return response()->json([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
            'details' => $details,
        ], $status, ['X-Request-ID' => $requestId]);
    }
}
