<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service endpoints for an organization's own admin (as opposed to
 * App\Http\Controllers\Api\Admin\OrganizationController, which is the
 * platform admin review workflow). Every route here sits behind
 * 'auth:sanctum' + 'organization.approved', so a pending or rejected
 * organization account cannot reach it even with a valid Sanctum token.
 */
class OrganizationController extends Controller
{
    /**
     * GET /api/organization/profile
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();

        // The organization returned and the organization that establishes
        // admin rights MUST be the same row. This previously used
        // organizations()->first() for the payload while the permission
        // check was a separate exists() across *all* of the user's
        // memberships — so a user who administered organization B but was
        // only a plain member of organization A could read A's profile.
        // The pivot status is filtered too, so a membership marked
        // 'removed' no longer grants access.
        $organization = $user->organizations()
            ->wherePivot('role_in_org', 'admin')
            ->wherePivot('status', 'active')
            ->first();

        if (! $organization) {
            // Distinguish "no organization at all" from "not an active admin
            // of one" purely to keep the existing UX copy; both are 403.
            if (! $user->organizations()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This account is not linked to any organization.',
                ], 403);
            }

            return response()->json([
                'success' => false,
                'message' => 'Only an organization admin can view this profile.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Organization profile retrieved successfully.',
            'data' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'type' => $organization->type,
                'verification_status' => $organization->verification_status,
                'contact_email' => $organization->contact_email,
                'contact_phone' => $organization->contact_phone,
                'website' => $organization->website,
                'industry' => $organization->industry,
                'company_size' => $organization->company_size,
                'country' => $organization->country,
                'city' => $organization->city,
            ],
        ]);
    }
}
