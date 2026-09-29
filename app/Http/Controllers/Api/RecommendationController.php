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

    public function __construct(
        private readonly ProjectAccessService $accessService = new ProjectAccessService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $requestId = (string) ($request->header('X-Request-ID') ?: Str::uuid());

        $recommendations = Recommendation::query()
            ->where('user_id', $request->user()->id)
            ->where('type', 'project')
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        // Only projects the learner may STILL discover are attached. The
        // recommendation row itself is always returned — historical records are
        // never deleted — but project details are withheld once the learner has
        // lost access. A project that became restricted to another organization
        // (or closed, or past its deadline) must not keep leaking its content
        // through the recommendation list.
        //
        // `project` is already nullable in RecommendationResource, so the
        // response shape is unchanged; it just goes null more often.
        $candidateIds = collect($recommendations->items())
            ->pluck('candidate_id')
            ->unique()
            ->all();

        $projects = $this->accessService->accessibleProjectsQuery(
            $this->accessService->activeOrganizationIds($request->user()),
            ['organization:id,title', 'requiredSkills.skill:id,name', 'eligibilityConstraints'],
        )
            ->whereIn('id', $candidateIds)
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
     * Task 12 — GET /api/v1/projects/{project}/recommendation
     *
     * Return the latest persisted explanation for this learner + project.
     * This endpoint NEVER recalculates matching and never calls FastAPI; it
     * only reads the Task 11 recommendation rows.
     *
     * Authorization is enforced with the SAME rules as project discovery
     * (ProjectAccessService), and it is checked BEFORE anything is read back:
     * a learner who has lost access to the project — membership removed, the
     * project made restricted to another organization, closed, or past its
     * deadline — must not receive its score, reasons, limiting factors or
     * version metadata. Stored rows are never deleted; only their exposure
     * stops.
     *
     * An inaccessible project and a nonexistent one return the identical
     * response, so the endpoint cannot be used to probe for the existence of
     * restricted projects. A project the learner CAN see but that has no stored
     * recommendation is reported distinctly, which reveals nothing they could
     * not already read from the project catalog.
     */
    public function showForProject(Request $request, int $project): JsonResponse
    {
        $requestId = (string) ($request->header('X-Request-ID') ?: Str::uuid());

        $learner = $request->user();

        // One query covers existence AND authorization, and answers `false` for
        // both "does not exist" and "not accessible to this learner".
        $isAccessible = $this->accessService->canAccessId($project, $learner);

        if (! $isAccessible) {
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
                'score' => $recommendation->score !== null ? (float) $recommendation->score : null,
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

        return response()->json($payload, $status, ['X-Request-ID' => $requestId]);
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
