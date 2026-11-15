<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ReadinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MatchProjectRequest;
use App\Http\Resources\ProjectMatchingResource;
use App\Models\Project;
use App\Services\Projects\ProjectMatchingRecommendationService;
use App\Services\Projects\ProjectMatchingService;
use App\Services\Projects\ProjectMatchingSnapshotService;
use App\Support\SafeLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProjectMatchingController extends Controller
{
    public function __construct(
        private readonly ProjectMatchingSnapshotService $snapshotService,
        private readonly ProjectMatchingService $matchingService,
        private readonly ProjectMatchingRecommendationService $recommendationService,
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

            $this->recommendationService->persist($user, $snapshot, $result);

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
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            Log::error('Project matching failed.', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'project_id' => $project->id,
                'failure_reason' => SafeLog::reason($e),
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
