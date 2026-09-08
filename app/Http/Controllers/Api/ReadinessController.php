<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ReadinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CalculateReadinessRequest;
use App\Http\Resources\ReadinessResultResource;
use App\Services\Readiness\ReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReadinessController extends Controller
{
    public function __construct(private readonly ReadinessService $readinessService) {}

    public function calculate(CalculateReadinessRequest $request): ReadinessResultResource|JsonResponse
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
            $result = $this->readinessService->calculate(
                $studentProfile,
                $request->integer('career_role_id') ?: null,
                $requestId,
            );

            return (new ReadinessResultResource($result))
                ->response()
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
            Log::error('Readiness calculation failed.', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'career_role_id' => $request->input('career_role_id'),
                'failure_reason' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'READINESS_CALCULATION_FAILED',
                'The readiness calculation could not be completed.',
                500,
                $requestId,
            );
        }
    }

    public function latest(Request $request): ReadinessResultResource|JsonResponse
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
            $careerRoleId = $request->integer('career_role_id') ?: null;
            $result = $this->readinessService->latest($studentProfile, $careerRoleId);

            if (! $result) {
                return $this->errorResponse(
                    'READINESS_NOT_FOUND',
                    'No readiness result is available for this learner.',
                    404,
                    $requestId,
                );
            }

            return (new ReadinessResultResource($result))
                ->response()
                ->header('X-Request-ID', $requestId);
        } catch (Throwable $e) {
            Log::error('Latest readiness lookup failed.', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'career_role_id' => $request->input('career_role_id'),
                'failure_reason' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'READINESS_LOOKUP_FAILED',
                'The readiness result could not be retrieved.',
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
