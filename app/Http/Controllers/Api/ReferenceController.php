<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Specialization;
use App\Models\University;
use App\Support\ReferenceCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Public reference data used by the frontend to populate onboarding
 * dropdowns. These endpoints are intentionally NOT behind auth so a
 * learner can render the university / specialization / country selects
 * while completing their profile (and before any token exists).
 *
 * Every list here is cached — see {@see ReferenceCache} for why. The payloads
 * are stored as plain arrays, not Eloquent models, so the cached copy is the
 * exact JSON the endpoint already returned; the response contract is unchanged.
 */
class ReferenceController extends Controller
{
    public function universities(): JsonResponse
    {
        $universities = Cache::remember(
            ReferenceCache::UNIVERSITIES,
            ReferenceCache::TTL_SECONDS,
            fn (): array => University::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->toArray(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Universities retrieved successfully.',
            'data' => $universities,
        ]);
    }

    public function specializations(): JsonResponse
    {
        $specializations = Cache::remember(
            ReferenceCache::SPECIALIZATIONS,
            ReferenceCache::TTL_SECONDS,
            fn (): array => Specialization::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->toArray(),
        );

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
        $countries = Cache::remember(
            ReferenceCache::COUNTRIES,
            ReferenceCache::TTL_SECONDS,
            fn (): array => Country::query()
                ->orderBy('name')
                ->get(['id', 'name', 'name_ar', 'iso2', 'iso3'])
                ->toArray(),
        );

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
     *
     * The route-model binding still costs one round trip; the per-country list
     * it feeds is cached so the second lookup of the same country is free.
     */
    public function countryUniversities(Country $country): JsonResponse
    {
        $universities = Cache::remember(
            ReferenceCache::countryUniversities($country->id),
            ReferenceCache::TTL_SECONDS,
            fn (): array => University::query()
                ->where('country_id', $country->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->toArray(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Universities retrieved successfully.',
            'data' => $universities,
        ]);
    }
}
