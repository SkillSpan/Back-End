<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Company\Concerns\ResolvesCompanyOrganization;
use App\Http\Controllers\Controller;
use App\Services\Company\OrganizationProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service endpoints for an organization's own admin (as opposed to
 * App\Http\Controllers\Api\Admin\OrganizationController, which is the
 * platform admin review workflow). Every route here sits behind
 * 'auth:sanctum' + 'organization.approved', so a pending or rejected
 * organization account cannot reach it even with a valid Sanctum token.
 *
 * US-COMP-04 moved the payload into OrganizationProfileService so that this
 * path and `/api/v1/profile/me` cannot drift apart: the story added a second
 * endpoint, and a second copy of the completeness rules is exactly how the
 * two screens end up showing different percentages for the same company.
 * This class is now only the HTTP adapter.
 */
class OrganizationController extends Controller
{
    use ResolvesCompanyOrganization;

    public function __construct(private readonly OrganizationProfileService $profiles) {}

    /**
     * GET /api/v1/organization/profile
     */
    public function profile(Request $request): JsonResponse
    {
        // The organization returned and the organization that establishes
        // admin rights MUST be the same row. This previously used
        // organizations()->first() for the payload while the permission
        // check was a separate exists() across *all* of the user's
        // memberships — so a user who administered organization B but was
        // only a plain member of organization A could read A's profile.
        // The pivot status is filtered too, so a membership marked
        // 'removed' no longer grants access.
        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            $denial = $this->organizationDenial($request);

            // Distinguish "no organization at all" from "not an active admin
            // of one" purely to keep the existing UX copy; both are 403.
            return response()->json([
                'success' => false,
                'message' => $denial['code'] === 'NO_ORGANIZATION'
                    ? 'This account is not linked to any organization.'
                    : 'Only an organization admin can view this profile.',
                'code' => $denial['code'],
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Organization profile retrieved successfully.',
            'data' => $this->profiles->payload($organization),
        ]);
    }
}
