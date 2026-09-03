<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Specialization;
use App\Models\University;
use Illuminate\Http\JsonResponse;

/**
 * Public reference data used by the frontend to populate onboarding
 * dropdowns. These endpoints are intentionally NOT behind auth so a
 * learner can render the university / specialization / country selects
 * while completing their profile (and before any token exists).
 */
class ReferenceController extends Controller
{
    public function universities(): JsonResponse
    {
        $universities = University::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'message' => 'Universities retrieved successfully.',
            'data' => $universities,
        ]);
    }

    public function specializations(): JsonResponse
    {
        $specializations = Specialization::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'message' => 'Specializations retrieved successfully.',
            'data' => $specializations,
        ]);
    }

    /**
     * GET /api/v1/reference/countries
     * Backs the onboarding country dropdown (countries task). Alphabetical
     * by English name so the list is stable across requests/locales.
     */
    public function countries(): JsonResponse
    {
        $countries = Country::query()
            ->orderBy('name')
            ->get(['id', 'name', 'name_ar', 'iso2', 'iso3']);

        return response()->json([
            'success' => true,
            'message' => 'Countries retrieved successfully.',
            'data' => $countries,
        ]);
    }

    /**
     * GET /api/v1/reference/countries/{country}/universities
     * Dependent select: only the active universities of the requested
     * country. Explicit columns — no N+1 (single query, no relations).
     */
    public function countryUniversities(Country $country): JsonResponse
    {
        $universities = University::query()
            ->where('country_id', $country->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'message' => 'Universities retrieved successfully.',
            'data' => $universities,
        ]);
    }
}
