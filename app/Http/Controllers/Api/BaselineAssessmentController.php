<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BaselineAssessmentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveBaselineProgressRequest;
use App\Http\Requests\StartBaselineAssessmentRequest;
use App\Http\Requests\SubmitBaselineAssessmentRequest;
use App\Models\BaselineAssessment;
use App\Services\Baseline\BaselineAssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BaselineAssessmentController extends Controller
{
    public function __construct(private readonly BaselineAssessmentService $assessmentService) {}

    public function start(StartBaselineAssessmentRequest $request): JsonResponse
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
            $assessment = $this->assessmentService->start(
                $studentProfile,
                $requestId,
            );

            return response()->json([
                'success' => true,
                'message' => 'Baseline assessment started.',
                'data' => $this->transform($assessment),
            ], 201, ['X-Request-ID' => $requestId]);
        } catch (BaselineAssessmentException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->handleUnexpected($e, 'Baseline assessment could not be started.', $requestId, $user->id);
        }
    }

    public function show(Request $request, BaselineAssessment $assessment): JsonResponse
    {
        $requestId = $this->requestId($request);
        $user = $request->user();

        if (! $this->owns($assessment, $user->id)) {
            return $this->errorResponse(
                'ASSESSMENT_NOT_FOUND',
                'The requested baseline assessment does not exist.',
                404,
                $requestId,
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Baseline assessment retrieved successfully.',
            'data' => $this->transform($assessment),
        ], 200, ['X-Request-ID' => $requestId]);
    }

    public function progress(SaveBaselineProgressRequest $request, BaselineAssessment $assessment): JsonResponse
    {
        $requestId = $this->requestId($request);
        $user = $request->user();

        if (! $this->owns($assessment, $user->id)) {
            return $this->errorResponse(
                'ASSESSMENT_NOT_FOUND',
                'The requested baseline assessment does not exist.',
                404,
                $requestId,
            );
        }

        try {
            $assessment = $this->assessmentService->saveProgress(
                $assessment,
                $request->input('progress'),
                $request->input('responses'),
                $requestId,
            );

            return response()->json([
                'success' => true,
                'message' => 'Baseline assessment progress saved.',
                'data' => $this->transform($assessment),
            ], 200, ['X-Request-ID' => $requestId]);
        } catch (BaselineAssessmentException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->handleUnexpected($e, 'Baseline assessment progress could not be saved.', $requestId, $user->id);
        }
    }

    public function submit(SubmitBaselineAssessmentRequest $request, BaselineAssessment $assessment): JsonResponse
    {
        $requestId = $this->requestId($request);
        $user = $request->user();

        if (! $this->owns($assessment, $user->id)) {
            return $this->errorResponse(
                'ASSESSMENT_NOT_FOUND',
                'The requested baseline assessment does not exist.',
                404,
                $requestId,
            );
        }

        try {
            $assessment = $this->assessmentService->submit(
                $assessment,
                $request->input('responses', []),
                $requestId,
            );

            return response()->json([
                'success' => true,
                'message' => 'Baseline assessment completed successfully.',
                'data' => $this->transform($assessment),
            ], 200, ['X-Request-ID' => $requestId]);
        } catch (BaselineAssessmentException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->handleUnexpected($e, 'Baseline assessment could not be completed.', $requestId, $user->id);
        }
    }

    private function owns(BaselineAssessment $assessment, int $userId): bool
    {
        return $assessment->studentProfile !== null
            && $assessment->studentProfile->user_id === $userId;
    }

    private function transform(BaselineAssessment $assessment): array
    {
        return [
            'id' => $assessment->id,
            'assessment_type' => $assessment->assessment_type,
            'assessment_version' => $assessment->assessment_version,
            'status' => $assessment->status,
            'progress' => $assessment->progress,
            'responses' => $assessment->responses,
            'result' => $assessment->result,
            'normalized_skills' => $assessment->normalized_skills,
            'completed_at' => $assessment->completed_at?->toIso8601String(),
            'created_at' => $assessment->created_at?->toIso8601String(),
            'updated_at' => $assessment->updated_at?->toIso8601String(),
        ];
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

    private function handleUnexpected(Throwable $e, string $message, string $requestId, int $userId): JsonResponse
    {
        Log::error($message, [
            'request_id' => $requestId,
            'user_id' => $userId,
            'failure_reason' => $e->getMessage(),
        ]);

        return $this->errorResponse(
            'BASELINE_ASSESSMENT_FAILED',
            $message,
            500,
            $requestId,
        );
    }
}
