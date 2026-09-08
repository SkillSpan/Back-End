<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\BaselineAssessmentItem;
use Illuminate\Http\Request;

class BaselineItemsController extends Controller
{
    /**
     * GET /api/v1/internal/baseline-items?version=v1.0
     *
     * Server-to-server endpoint for the Data Science FastAPI service.
     * Backend is the source of truth for assessment content — FastAPI
     * must not invent item-to-skill mapping or correct answers itself.
     *
     * Auth: shared secret via X-Internal-Secret header (not Sanctum —
     * this isn't called on behalf of a logged-in user).
     */
    public function index(Request $request)
    {
        $expectedSecret = config('services.internal.baseline_items_secret');

        if (blank($expectedSecret) || $request->header('X-Internal-Secret') !== $expectedSecret) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $request->validate([
            'version' => 'required|string',
        ]);

        $items = BaselineAssessmentItem::query()
            ->where('assessment_version', $request->query('version'))
            ->where('is_active', true)
            ->with('skill:id,slug')
            ->get()
            ->map(fn (BaselineAssessmentItem $item) => [
                'item_id' => $item->item_id,
                'item_type' => $item->item_type,
                'options' => $item->options,
                'correct_answer' => $item->correct_answer,
                'scoring_rule' => $item->scoring_rule,
                'skill_id' => $item->skill_id,
                'skill_slug' => $item->skill?->slug,
                'weight' => (float) $item->weight,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'assessment_version' => $request->query('version'),
            'weight_scale' => '0..1',
            'items' => $items,
        ]);
    }
}
