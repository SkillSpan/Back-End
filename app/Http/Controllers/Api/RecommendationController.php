
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecommendationResource;
use App\Models\Recommendation;
use App\Services\Projects\ProjectAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Task 11 — GET /api/v1/recommendations
 *
 * Returns the authenticated learner's stored project recommendations.
 * Inaccessible projects are retained as historical records, but sensitive
 * recommendation details are redacted.
 */
class RecommendationController extends Controller
{
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly ProjectAccessService $accessService = new ProjectAccessService,
    ) {}

    /**
     * GET /api/v1/recommendations
     *
     * Returns the learner's recommendations while enforcing project access.
     * Historical records remain visible, but inaccessible project details
     * and sensitive recommendation metadata are withheld.
     */
    public function index(Request $request): JsonResponse
    {
        $requestId = (string) (
            $request->header('X-Request-ID') ?: Str::uuid()
        );

        $recommendations = Recommendation::query()
            ->where('user_id', $request->user()->id)
            ->where('type', 'project')
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        $candidateIds = collect($recommendations->items())
            ->pluck('candidate_id')
            ->unique()
            ->all();

        // Only attach projects the learner is still allowed to discover.
        $projects = $this->accessService->accessibleProjectsQuery(
            $this->accessService->activeOrganizationIds($request->user()),
            [
                'organization:id,title',
                'requiredSkills.skill:id,name',
                'eligibilityConstraints',
            ],
        )
            ->whereIn('id', $candidateIds)
            ->get()
            ->keyBy('id');

        $data = collect($recommendations->items())
            ->map(function (Recommendation $recommendation) use ($projects) {
                $project = $projects->get(
                    (int) $recommendation->candidate_id
                );

                // Preserve the historical record without exposing details
                // when the learner no longer has access to the project.
                if ($project === null) {
                    return [
                        'id' => $recommendation->id,
                        'project_id' => (int) $recommendation->candidate_id,
                        'project' => null,
                        'access_revoked' => true,
                    ];
                }

                $resource = new RecommendationResource($recommendation);
                $resource->project = $project;

                $result = $resource->toArray(request());
                $result['access_revoked'] = false;

                return $result;
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
     * Task 12 — GET /api/v1/projects/{project}/recommendation
     *
     * Returns the latest stored recommendation for an accessible project.
     * Access is checked before retrieving the recommendation.
     */
    public function showForProject(
        Request $request,
        int $project
    ): JsonResponse {
        $requestId = (string) (
            $request->header('X-Request-ID') ?: Str::uuid()
        );

        $learner = $request->user();

        // Nonexistent and inaccessible projects return the same response.
        if (! $this->accessService->canAccessId($project, $learner)) {
            return $this->errorResponse(
                'PROJECT_NOT_FOUND',
                'The requested project does not exist.',
                404,
                $requestId,
                ['project_id' => $project],
            );
        }

        $recommendation = Recommendation::query()
            ->where('user_id', $learner->id)
            ->where('type', 'project')
            ->where('candidate_type', 'project')
            ->where('candidate_id', $project)
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->first();

        if ($recommendation === null) {
            return $this->errorResponse(
                'RECOMMENDATION_NOT_FOUND',
                'No stored recommendation was found for this project.',
                404,
                $requestId,
                ['project_id' => $project],
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Project recommendation retrieved successfully.',
            'data' => [
                'project_id' => (int) $recommendation->candidate_id,
                'score' => $recommendation->score !== null
                    ? (float) $recommendation->score
                    : null,
                'reasons' => $recommendation->reasons,
                'limiting_factors' => $recommendation->limiting_factors,
                'algorithm_version' => $recommendation->algorithm_version,
                'configuration_version' => $recommendation->configuration_version,
            ],
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    private function errorResponse(
        string $code,
        string $message,
        int $status,
        string $requestId,
        array $details = [],
    ): JsonResponse {
        $payload = [
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ];

        if ($details !== []) {
            $payload['details'] = $details;
        }

        return response()->json(
            $payload,
            $status,
            ['X-Request-ID' => $requestId]
        );
    }

    /**
     * Clamp the requested page size to prevent unbounded result sets.
     */
    private function perPage(Request $request): int
    {
        $requested = (int) $request->query(
            'per_page',
            self::DEFAULT_PER_PAGE
        );

        if ($requested < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($requested, self::MAX_PER_PAGE);
    }
}