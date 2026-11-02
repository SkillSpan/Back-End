<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Company\CompanyDashboardService;
use App\Traits\AuditsActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * US-COMP-03 — Company Dashboard & Overview.
 *
 * GET /api/v1/company/dashboard
 *
 * The route already guarantees an authenticated, active account with the
 * company role whose organization is approved (`organization.approved`). What
 * is left here is the one thing a route middleware cannot decide: WHICH
 * organization. It is resolved from the caller's own membership and never
 * from the request, so there is no parameter to tamper with (BR-02 / AC-13).
 *
 * The heavy lifting — and every number — lives in CompanyDashboardService;
 * this class is a thin adapter, matching the rest of the API.
 */
class CompanyDashboardController extends Controller
{
    use AuditsActions;

    public function __construct(private readonly CompanyDashboardService $dashboard) {}

    public function show(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);
        $user = $request->user();

        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            $reason = $user->organizations()->exists() ? 'not_organization_admin' : 'no_organization';

            // AUTH-04 / Scenario 5 — a refusal is recorded. The row carries no
            // company data, only who asked and why they were turned away, so an
            // audit trail exists without the log itself becoming a leak.
            $this->audit(
                actorId: $user->id,
                action: 'company.dashboard.denied',
                entityType: 'organization',
                entityId: null,
                after: ['reason' => $reason],
                requestId: $requestId,
                ipAddress: $request->ip(),
                userAgent: (string) $request->userAgent(),
            );

            // Distinguish "no organization at all" from "member but not admin",
            // because the two are different support conversations. Neither
            // reveals any company data.
            return response()->json([
                'success' => false,
                'code' => $user->organizations()->exists() ? 'NOT_ORGANIZATION_ADMIN' : 'NO_ORGANIZATION',
                'message' => $user->organizations()->exists()
                    ? 'Only an organization admin can view the company dashboard.'
                    : 'This account is not linked to any organization.',
                'request_id' => $requestId,
            ], 403, ['X-Request-ID' => $requestId]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Company dashboard retrieved successfully.',
            'data' => $this->dashboard->summary($organization, $user->id),
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * The organization this caller administers.
     *
     * Same lookup as OrganizationController::profile() — one row, and it is
     * the same row that establishes admin rights. A user who administers
     * organization B but is only a plain member of A must not read A.
     */
    private function resolveOrganization(Request $request): ?Organization
    {
        return $request->user()
            ->organizations()
            ->wherePivot('role_in_org', 'admin')
            ->wherePivot('status', 'active')
            ->first();
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
    }
}
