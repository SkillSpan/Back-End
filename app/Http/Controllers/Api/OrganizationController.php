<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Company\Concerns\ResolvesCompanyOrganization;
use App\Http\Controllers\Controller;
use App\Services\Company\OrganizationProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    use ResolvesCompanyOrganization;

    public function __construct(private readonly OrganizationProfileService $profiles) {}

    public function profile(Request $request): JsonResponse
    {
        $organization = $this->resolveOrganization($request);

        if ($organization === null) {
            $denial = $this->organizationDenial($request);

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
