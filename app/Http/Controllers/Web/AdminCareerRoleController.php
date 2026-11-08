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

/**
 * Session-authenticated admin endpoints for career-role activation.
 *
 * WHY THESE EXIST
 * ---------------
 * `career_roles.is_active` decides whether a role is offered to learners, but
 * there was no backend way to change it: an operator could create a role (now
 * inactive by default) and had no way to publish it, and could not retire a
 * single role without editing config/mvp.php and re-running the seeder.
 *
 * These endpoints are ADDITIVE. They live under `/admin/api/career-roles` on
 * the normal web session, exactly like the organizations / projects /
 * questions / specializations panels, and the route group already carries
 * `auth` + `account.active` + `admin` — no middleware is weakened and no
 * existing endpoint changes shape.
 *
 * All rules live in CareerRoleAdminService; this class only shapes HTTP.
 */
class AdminCareerRoleController extends Controller
{
    public function __construct(
        private readonly CareerRoleAdminService $service,
    ) {}

    /**
     * GET /admin/api/career-roles
     *
     * Paginated list of career roles with their activation state, so an
     * operator can find the role to switch. Filters: `q` (title or slug),
     * `status` (draft|approved|retired) and `is_active` (0|1).
     */
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

    /**
     * POST /admin/api/career-roles/{careerRole}/activate
     *
     * Refused with 409 `CAREER_ROLE_ACTIVATION_REFUSED` when the role is not
     * approved — activation offers the role to learners, so an unreviewed
     * definition must never be published.
     */
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

    /**
     * POST /admin/api/career-roles/{careerRole}/deactivate
     *
     * Removes the role from every picker. Nothing is deleted: the row keeps its
     * required skills, its questions, its pivot links and any assessment,
     * readiness or project that references it.
     */
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

    // -----------------------------------------------------------------
    // Response helpers
    // -----------------------------------------------------------------

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

    /**
     * @return array<string, mixed>
     */
    private function transform(CareerRole $careerRole): array
    {
        return [
            'id' => (int) $careerRole->id,
            'title' => (string) $careerRole->title,
            'slug' => (string) $careerRole->slug,
            'status' => (string) $careerRole->status,
            // Exposed HERE only: the admin needs to see the flag it is
            // switching. The public career-role payload still hides it.
            'is_active' => (bool) $careerRole->is_active,
            'skills_count' => (int) ($careerRole->skills_count ?? $careerRole->roleSkills()->count()),
        ];
    }

    /**
     * `?is_active=` accepts 1/0, "true"/"false" and nothing else; an
     * unrecognised value means "no filter" rather than an error, matching how
     * the other admin list endpoints treat optional query filters.
     */
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
