<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Evaluation\RubricService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RubricController extends Controller
{
    public function __construct(private readonly RubricService $rubrics) {}

    public function index(Request $request): JsonResponse
    {
        $requestId = (string) ($request->header('X-Request-ID') ?: Str::uuid());

        $rubrics = $this->rubrics->selectable()->map(fn ($rubric) => [
            'id' => $rubric->id,
            'name' => $rubric->title,
            'title' => $rubric->title,
            'description' => $rubric->description,
            'version' => (int) $rubric->version,
            'status' => 'approved',
            'criteria_count' => $rubric->dimensions->count(),
            'score_min' => (float) $rubric->score_min,
            'score_max' => (float) $rubric->score_max,
            'applicable_project_types' => $rubric->applicable_project_types ?? [],
            'published_at' => $rubric->published_at?->toIso8601String(),
            'dimensions' => $rubric->dimensions->map(fn ($dimension) => [
                'id' => $dimension->id,
                'name' => $dimension->name,
                'weight' => (float) $dimension->weight,
                'score_min' => (float) $dimension->score_min,
                'score_max' => (float) $dimension->score_max,
                'indicators' => $dimension->indicators ?? [],
            ])->all(),
        ])->values();

        return response()->json([
            'success' => true,
            'message' => 'Rubrics retrieved successfully.',
            'data' => $rubrics,
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }
}
