<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Company\CompanyDashboardService;
use App\Traits\AuditsActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
