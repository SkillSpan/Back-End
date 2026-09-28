<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ReadinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MatchProjectRequest;
use App\Http\Resources\ProjectMatchingResource;
use App\Models\Project;
use App\Services\Projects\ProjectMatchingService;
use App\Services\Projects\ProjectMatchingSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Task 10 — POST /api/v1/projects/{project}/match
 *
 * Thin controller, following the project's existing convention
 * (validation in a FormRequest, orchestration in services, presentation
 * in a resource). Two services are composed here and neither is
 * duplicated:
 *
 *   1. ProjectMatchingSnapshotService (Task 8) validates availability,
 *      authorization and eligibility, then persists one immutable
 *      validated snapshot.
 *   2. ProjectMatchingService (Task 10) builds the FastAPI payload,
 *      calls POST /api/v1/project-matching through IntelligenceClient,
 *      validates the response schema and correlation, and normalizes it.
 *
 * No recommendations are persisted — the result is returned to the
 * caller only. Every failure mode is a typed ReadinessException
 * (IntelligenceException) carrying a stable `codeName` and HTTP status,
 * which maps straight onto the shared error envelope below.
 *
 * Request-id note: the envelope's top-level `request_id` is the caller's
 * X-Request-ID (or a generated one), matching every other controller in
 * this codebase. The id that travels to FastAPI is the snapshot's own
 * `request_id`, surfaced as `data.request_id`. The two are deliberately
 * distinct and both are preserved.
 */
class ProjectMatchingController extends Controller
{
    public function __construct(
        private readonly ProjectMatchingSnapshotService $snapshotService,
        private readonly ProjectMatchingService $matchingService,
    ) {}

    public function match(MatchProjectRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);
        $user = $request->user();

        $projectId = (int) $request->route('project');
        $project = Project::query()->find($projectId);

        if (! $project) {
            return $this->errorResponse(
                'PROJECT_NOT_FOUND',
                'The requested project does not exist.',
                404,
                $requestId,
                ['project_id' => $projectId],
            );
        }

        try {
            $snapshot = $this->snapshotService->createForProject($project, $user);

            $result = $this->matchingService->match($snapshot);

            // Same success envelope as the sibling project endpoints
            // (ProjectController): success / message / data / request_id.
            return (new ProjectMatchingResource($result))
                ->additional([
                    'success' => true,
                    'message' => 'Project matching recommendation calculated successfully.',
                    'request_id' => $requestId,
                ])
                ->response()
                ->setStatusCode(200)
                ->header('X-Request-ID', $requestId);
        } catch (ReadinessException $e) {
            // IntelligenceException extends ReadinessException, so every
            // domain failure from either service lands here with its own
            // status and stable code name (PROJECT_MATCH_*, INTELLIGENCE_*).
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            Log::error('Project matching failed.', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'project_id' => $project->id,
                'failure_reason' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'PROJECT_MATCHING_FAILED',
                'The project matching calculation could not be completed.',
                500,
                $requestId,
            );
        }
    }

    private function requestId(Request $request): string
    {
        // Correlate with the incoming X-Request-ID when present;
        // otherwise generate one server-side. Same pattern as
        // IntelligenceController and ProjectController.
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
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
}
