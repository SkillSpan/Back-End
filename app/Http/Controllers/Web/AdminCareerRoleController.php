<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\CareerRoleException;
use App\Http\Controllers\Controller;
use App\Models\CareerRole;
use App\Services\CareerRoles\CareerRoleAdminService;
use App\Support\SafeLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AdminCareerRoleController extends Controller
{
    public function __construct(
        private readonly CareerRoleAdminService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $status = $request->query('status');

        $careerRoles = $this->service->paginate(
            trim((string) $request->query('q', '')),
            in_array($status, ['draft', 'approved', 'retired'], true) ? $status : null,
            $this->boolOrNull($request->query('is_active')),
            max(1, min((int) $request->query('per_page', 20), 100)),
        );

        return response()->json([
            'success' => true,
            'message' => 'Career roles retrieved successfully.',
            'data' => [
                'data' => collect($careerRoles->items())
                    ->map(fn (CareerRole $careerRole) => $this->transform($careerRole))
                    ->values()
                    ->all(),
                'current_page' => $careerRoles->currentPage(),
                'per_page' => $careerRoles->perPage(),
                'total' => $careerRoles->total(),
                'last_page' => $careerRoles->lastPage(),
                'from' => $careerRoles->firstItem(),
                'to' => $careerRoles->lastItem(),
            ],
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    public function activate(Request $request, CareerRole $careerRole): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $updated = $this->service->setActive($careerRole, true);

            return $this->careerRoleResponse($updated, 'Career role activated.', 200, $requestId);
        } catch (CareerRoleException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    public function deactivate(Request $request, CareerRole $careerRole): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $updated = $this->service->setActive($careerRole, false);

            return $this->careerRoleResponse($updated, 'Career role deactivated.', 200, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    private function careerRoleResponse(
        CareerRole $careerRole,
        string $message,
        int $status,
        string $requestId,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $this->transform($careerRole),
            'request_id' => $requestId,
        ], $status, ['X-Request-ID' => $requestId]);
    }

    private function transform(CareerRole $careerRole): array
    {
        return [
            'id' => (int) $careerRole->id,
            'title' => (string) $careerRole->title,
            'slug' => (string) $careerRole->slug,
            'status' => (string) $careerRole->status,
            'is_active' => (bool) $careerRole->is_active,
            'skills_count' => (int) ($careerRole->skills_count ?? $careerRole->roleSkills()->count()),
        ];
    }

    private function boolOrNull(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match (strtolower((string) $value)) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => null,
        };
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
    }

    private function unexpected(Throwable $e, string $requestId): JsonResponse
    {
        Log::error('Admin career role request failed.', [
            'request_id' => $requestId,
            'failure_reason' => SafeLog::reason($e),
        ]);

        return $this->errorResponse(
            'CAREER_ROLE_REQUEST_FAILED',
            'The career role request could not be completed.',
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
