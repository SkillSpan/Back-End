<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rubric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RubricController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $requestId = (string) ($request->header('X-Request-ID') ?: Str::uuid());

        $status = (string) $request->query('status', 'approved');

        $stored = $status === 'approved' ? 'published' : $status;

        $query = Rubric::query()
            ->withCount('dimensions')
            ->orderByDesc('version')
            ->orderBy('title');

        if ($stored !== '' && $stored !== 'all') {
            $query->where('status', $stored);
        }

        $rubrics = $query->get()->map(fn (Rubric $rubric) => [
            'id' => $rubric->id,
            'name' => $rubric->title,
            'title' => $rubric->title,
            'version' => $rubric->version,
            'status' => $rubric->status === 'published' ? 'approved' : $rubric->status,
            'criteria_count' => $rubric->dimensions_count,
            'published_at' => $rubric->published_at?->toIso8601String(),
        ])->values();

        return response()->json([
            'success' => true,
            'message' => 'Rubrics retrieved successfully.',
            'data' => $rubrics,
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }
}
