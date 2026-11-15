<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\BaselineAssessmentItem;
use Illuminate\Http\Request;

class BaselineItemsController extends Controller
{
    public function index(Request $request)
    {
        $expectedSecret = config('services.internal.baseline_items_secret');
        $providedSecret = (string) $request->header('X-Internal-Secret');

        if (blank($expectedSecret) || ! hash_equals($expectedSecret, $providedSecret)) {
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
                'question_text' => $item->question_text,
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
