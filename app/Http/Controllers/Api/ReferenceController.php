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

    public function countries(Request $request): JsonResponse
    {
        return ReferenceResponse::make(
            'Countries retrieved successfully.',
            $this->countryList(),
            $request,
        );
    }

    public function countryUniversities(string $country, Request $request): JsonResponse
    {
        $countryId = (int) $country;

        $universities = Cache::remember(
            ReferenceCache::countryUniversities($countryId),
            ReferenceCache::TTL_SECONDS,
            function () use ($countryId): array {
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

    private function ensureCountryExists(int $countryId): void
    {
        $knownIds = array_map('intval', array_column($this->countryList(), 'id'));

        if (! in_array($countryId, $knownIds, true)) {
            throw (new ModelNotFoundException)->setModel(Country::class, [$countryId]);
        }
    }
}
