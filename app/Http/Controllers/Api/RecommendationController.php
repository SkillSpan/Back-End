<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecommendationResource;
use App\Models\Recommendation;
use App\Services\Projects\ProjectAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RecommendationController extends Controller
{
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly ProjectAccessService $accessService = new ProjectAccessService,
    ) {}

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

        $projects = $this->accessService->accessibleProjectsQuery(
            $this->accessService->activeOrganizationIds($request->user()),
            [
                'organization:id,name',
                'requiredSkills.skill:id,name',
                'eligibilityConstraints',
            ],
        )
            ->whereIn('id', $candidateIds)
            ->get()
            ->keyBy('id');

        $data = collect($recommendations->items())
            ->map(function (Recommendation $recommendation) use ($projects) {
                $resource = new RecommendationResource($recommendation);

                $resource->project = $projects->get(
                    (int) $recommendation->candidate_id
                );

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

    public function showForProject(
        Request $request,
        int $project
    ): JsonResponse {
        $requestId = (string) (
            $request->header('X-Request-ID') ?: Str::uuid()
        );

        $learner = $request->user();

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
