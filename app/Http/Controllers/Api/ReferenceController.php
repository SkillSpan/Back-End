<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Specialization;
use App\Models\University;
use App\Support\ReferenceCache;
use App\Support\ReferenceResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
 *
 * The response is also marked cacheable in the browser — see
 * {@see ReferenceResponse} — because the lists are identical for every caller
 * and the largest of them is 541 KB.
 */
class ReferenceController extends Controller
{
    public function universities(Request $request): JsonResponse
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

        return ReferenceResponse::make(
            'Universities retrieved successfully.',
            $universities,
            $request,
        );
    }

    public function specializations(Request $request): JsonResponse
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

        return ReferenceResponse::make(
            'Specializations retrieved successfully.',
            $specializations,
            $request,
        );
    }

    /**
     * GET /api/v1/reference/countries
     * Backs the onboarding country dropdown (countries task). Alphabetical
     * by English name so the list is stable across requests/locales.
     */
    public function countries(Request $request): JsonResponse
    {
        return ReferenceResponse::make(
            'Countries retrieved successfully.',
            $this->countryList(),
            $request,
        );
    }

    /**
     * GET /api/v1/reference/countries/{country}/universities
     * Dependent select: only the active universities of the requested
     * country. Explicit columns — no N+1 (single query, no relations).
     *
     * WHY THE PARAMETER IS A STRING AND NOT `Country $country`
     * --------------------------------------------------------
     * Route-model binding resolves the model with a database query *before* the
     * controller runs, so even a cached list paid a full round trip first — the
     * endpoint measured 1.33 s on a cache HIT against 0.43 s for a list that
     * needed no binding. The database sits on another provider, so that round
     * trip is ~0.7 s.
     *
     * The id is instead validated against the already-cached country list, which
     * is the same data the binding would have read. An unknown id still throws
     * the same ModelNotFoundException, so the 404 is byte-for-byte what binding
     * produced, and a hit now touches no database at all.
     *
     * The parameter stays a `string`: a non-numeric segment must keep returning
     * 404. An `int` hint would throw a TypeError and turn it into a 500.
     */
    public function countryUniversities(string $country, Request $request): JsonResponse
    {
        $countryId = (int) $country;

        $universities = Cache::remember(
            ReferenceCache::countryUniversities($countryId),
            ReferenceCache::TTL_SECONDS,
            function () use ($countryId): array {
                // Only reached on a cache miss, so the validation costs nothing
                // on the hot path.
                $this->ensureCountryExists($countryId);

                return University::query()
                    ->where('country_id', $countryId)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->toArray();
            },
        );

        return ReferenceResponse::make(
            'Universities retrieved successfully.',
            $universities,
            $request,
        );
    }

    /**
     * The full country list, cached. Shared by the dropdown endpoint and by the
     * id validation above, so both read the same copy.
     *
     * @return array<int, array<string, mixed>>
     */
    private function countryList(): array
    {
        return Cache::remember(
            ReferenceCache::COUNTRIES,
            ReferenceCache::TTL_SECONDS,
            fn (): array => Country::query()
                ->orderBy('name')
                ->get(['id', 'name', 'name_ar', 'iso2', 'iso3'])
                ->toArray(),
        );
    }

    /**
     * Reproduces exactly what `Country $country` route-model binding used to
     * throw, so the 404 response is unchanged — without the query.
     *
     * @throws ModelNotFoundException
     */
    private function ensureCountryExists(int $countryId): void
    {
        $knownIds = array_map('intval', array_column($this->countryList(), 'id'));

        if (! in_array($countryId, $knownIds, true)) {
            throw (new ModelNotFoundException)->setModel(Country::class, [$countryId]);
        }
    }
}
