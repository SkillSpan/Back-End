<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecommendationResource;
use App\Models\Project;
use App\Models\Recommendation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Task 11 — GET /api/v1/recommendations
 *
 * Returns the authenticated learner's own stored project matching
 * recommendations, newest first. Scoping is by user_id, so one learner can
 * never read another's rows (recommendations is keyed on user_id; there is no
 * student_profile_id column — see AssistantContextBuilder for the same note).
 *
 * Pagination and the success/message/data/meta envelope follow the existing
 * notification-list convention; the associated project is presented with the
 * existing ProjectResource.
 */
class RecommendationController extends Controller
{
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        $requestId = (string) ($request->header('X-Request-ID') ?: Str::uuid());

        $recommendations = Recommendation::query()
            ->where('user_id', $request->user()->id)
            ->where('type', 'project')
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        $projects = Project::query()
            ->whereIn('id', collect($recommendations->items())->pluck('candidate_id')->unique()->all())
            ->with(['organization:id,name', 'requiredSkills.skill:id,name', 'eligibilityConstraints'])
            ->get()
            ->keyBy('id');

        $data = collect($recommendations->items())
            ->map(function (Recommendation $recommendation) use ($projects) {
                $resource = new RecommendationResource($recommendation);
                $resource->project = $projects->get((int) $recommendation->candidate_id);

                return $resource->toArray(request());
            })
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Project recommendations retrieved successfully.',
            'data' => $data,
            'meta' => [
                'current_page' => $recommendations->currentPage(),
                'last_page' => $recommendations->lastPage(),
                'per_page' => $recommendations->perPage(),
                'total' => $recommendations->total(),
            ],
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * Clamp the requested page size so a caller cannot ask for an unbounded
     * result set.
     */
    private function perPage(Request $request): int
    {
        $requested = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);

        if ($requested < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($requested, self::MAX_PER_PAGE);
    }
}
