<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ReadinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CalculateIntelligenceRequest;
use App\Http\Resources\IntelligenceResultResource;
use App\Services\Intelligence\IntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * US-INT-01 — intelligence endpoints (skill gap + readiness + roadmap
 * in one atomic decision). Thin controller: validation via FormRequest,
 * orchestration in IntelligenceService, presentation in the resource.
 * Routes sit behind auth:sanctum + role:learner (routes/api.php).
 */
class IntelligenceController extends Controller
{
    public function __construct(private readonly IntelligenceService $intelligenceService) {}

    public function calculate(CalculateIntelligenceRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);
        $user = $request->user();

        $studentProfile = $user->studentProfile;

        if (! $studentProfile) {
            return $this->errorResponse(
                'STUDENT_PROFILE_NOT_FOUND',
                'The authenticated learner does not have a student profile.',
                422,
                $requestId,
            );
        }

        try {
            $result = $this->intelligenceService->calculate(
                $studentProfile,
                $request->integer('career_role_id') ?: null,
                $requestId,
            );

            return (new IntelligenceResultResource($result))
                ->response()
                ->setStatusCode(201)
                ->header('X-Request-ID', $requestId);
        } catch (ReadinessException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (ValidationException $e) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'The request could not be processed.',
                'errors' => $e->errors(),
                'request_id' => $requestId,
            ], 422, ['X-Request-ID' => $requestId]);
        } catch (Throwable $e) {
            Log::error('Intelligence calculation failed.', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'career_role_id' => $request->input('career_role_id'),
                'failure_reason' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'INTELLIGENCE_CALCULATION_FAILED',
                'The intelligence calculation could not be completed.',
                500,
                $requestId,
            );
        }
    }

    public function latest(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);
        $user = $request->user();

        $studentProfile = $user->studentProfile;

        if (! $studentProfile) {
            return $this->errorResponse(
                'STUDENT_PROFILE_NOT_FOUND',
                'The authenticated learner does not have a student profile.',
                422,
                $requestId,
            );
        }

        $snapshot = $studentProfile->decisionSnapshots()
            ->where('status', 'succeeded')
            ->when(
                $request->integer('career_role_id'),
                fn ($query, $roleId) => $query->where('career_role_id', $roleId),
            )
            ->latest('id')
            ->first();

        if (! $snapshot) {
            return $this->errorResponse(
                'DECISION_NOT_FOUND',
                'No successful intelligence decision is available for this learner.',
                404,
                $requestId,
            );
        }

        $result = [
            'snapshot' => $snapshot,
            'readiness' => $snapshot->readinessResults()->latest('id')->first(),
            'skill_gaps' => $snapshot->skillGapResults()->with('skill')->get()->all(),
            'roadmap' => $snapshot->roadmaps()->latest('id')->first(),
        ];

        return (new IntelligenceResultResource($result))
            ->response()
            ->header('X-Request-ID', $requestId);
    }

    private function requestId(Request $request): string
    {
        // Correlate with the incoming X-Request-ID when present;
        // otherwise generate one server-side (US-INT-01 §5). The same
        // ID travels to FastAPI, the logs, and the decision snapshot.
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
