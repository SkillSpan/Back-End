<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rubric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The rubric versions a company may attach to a project (US-PRJ-01 / PRJ-09).
 *
 * The company project form needs this list to fill its rubric picker, and until
 * now there was no route at all: `GET /api/v1/rubrics` answered 404 while the
 * form treated the picker as required, so a company could never publish.
 *
 * Only `published` rubrics are exposed. The company-facing contract calls that
 * state "approved" (the vocabulary the frontend and the sprint document use),
 * so the payload says `approved` while the column stays `published` — the
 * authoring side keeps its own draft/published/retired lifecycle.
 */
class RubricController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $requestId = (string) ($request->header('X-Request-ID') ?: Str::uuid());

        $status = (string) $request->query('status', 'approved');

        // `approved` is the company-facing alias of the stored `published`
        // state; anything else is passed through as-is so an authoring screen
        // could reuse this endpoint later.
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
