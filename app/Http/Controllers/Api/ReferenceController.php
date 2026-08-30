<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Specialization;
use App\Models\University;
use Illuminate\Http\JsonResponse;

/**
 * Public reference data used by the frontend to populate onboarding
 * dropdowns. These endpoints are intentionally NOT behind auth so a
 * learner can render the university / specialization selects while
 * completing their profile (and before any token exists).
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
}
