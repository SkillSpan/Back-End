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
        $organization = $request->user()->organizations()->first();

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
