<?php

namespace App\Services\Baseline;

use App\Exceptions\BaselineAssessmentException;
use App\Models\StudentProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-to-server client for the SkillSpan Intelligence (FastAPI) baseline
 * assessment endpoint. The result of a learner's baseline responses is
 * computed by the intelligence service and returned as an authoritative,
 * normalized payload — never trusted from the requesting client.
 *
 * Expected contract (must be implemented by the FastAPI service):
 *
 * POST {url}/api/v1/baseline
 * Request:
 * {
 *   "student_profile_id": 1,
 *   "user_id": 2,
 *   "assessment_version": "v1.0",
 *   "responses": { ... }
 * }
 *
 * Response 200:
 * {
 *   "algorithm_version": "baseline-v1.0",
 *   "student_profile_id": 1,
 *   "overall_score": 60,
 *   "skills": [
 *     { "skill_id": 7, "slug": "sql", "level": 2.5, "confidence": 0.7 },
 *     ...
 *   ]
 * }
 */
class BaselineDataScienceClient
{
    public function compute(
        StudentProfile $studentProfile,
        string $assessmentVersion,
        array $responses,
        string $requestId
    ): array {
        $baseUrl = rtrim(
            (string) config('services.data_science.url'),
            '/'
        );

        $enabled = (bool) config(
            'services.data_science.baseline.enabled',
            false
        );

        if (! $enabled || $baseUrl === '') {
            throw new BaselineAssessmentException(
                'The baseline intelligence integration is not enabled.',
                503,
                'BASELINE_INTEGRATION_NOT_CONFIGURED',
            );
        }

        $path = ltrim(
            (string) config('services.data_science.baseline.path', 'api/v1/baseline'),
            '/'
        );

        $timeout = (int) config(
            'services.data_science.timeout',
            10
        );

        $endpoint = $baseUrl.'/'.$path;

        $payload = [
            'student_profile_id' => (int) $studentProfile->id,
            'user_id' => (int) $studentProfile->user_id,
            'assessment_version' => $assessmentVersion,
            'responses' => $responses,
        ];

        $startedAt = microtime(true);

        try {
            $response = Http::acceptJson()
                ->contentType('application/json')
                ->timeout($timeout)
                ->withHeaders([
                    'X-Request-ID' => $requestId,
                ])
                ->post($endpoint, $payload);
        } catch (ConnectionException $e) {
            $this->logFailure($payload, $requestId, null, $startedAt, $e->getMessage());

            throw new BaselineAssessmentException(
                'The intelligence service is unavailable or timed out.',
                503,
                'INTELLIGENCE_SERVICE_UNAVAILABLE',
                [],
                $e,
            );
        } catch (Throwable $e) {
            $this->logFailure($payload, $requestId, null, $startedAt, $e->getMessage());

            throw new BaselineAssessmentException(
                'The intelligence integration failed unexpectedly.',
                502,
                'INTELLIGENCE_INTEGRATION_FAILED',
                [],
                $e,
            );
        }

        return $this->parseResponse($response, $payload, $requestId, $startedAt);
    }

    private function parseResponse(
        Response $response,
        array $payload,
        string $requestId,
        float $startedAt
    ): array {
        $status = $response->status();

        if ($status === 422) {
            $this->logFailure($payload, $requestId, $status, $startedAt, 'Intelligence validation error');

            throw new BaselineAssessmentException(
                'The intelligence service rejected the baseline request.',
                422,
                'INTELLIGENCE_VALIDATION_ERROR',
                $this->safeJson($response),
            );
        }

        if ($status >= 500) {
            $this->logFailure($payload, $requestId, $status, $startedAt, 'Intelligence server error');

            throw new BaselineAssessmentException(
                'The intelligence service failed to process the request.',
                503,
                'INTELLIGENCE_SERVICE_ERROR',
            );
        }

        if (! $response->successful()) {
            $this->logFailure($payload, $requestId, $status, $startedAt, 'Unexpected intelligence HTTP status');

            throw new BaselineAssessmentException(
                'The intelligence service returned an unexpected HTTP status.',
                502,
                'INTELLIGENCE_UNEXPECTED_STATUS',
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            $this->logFailure($payload, $requestId, $status, $startedAt, 'Invalid JSON response');

            throw new BaselineAssessmentException(
                'The intelligence service returned an invalid JSON response.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        Log::info(
            'Baseline intelligence request completed.',
            [
                'request_id' => $requestId,
                'student_profile_id' => $payload['student_profile_id'] ?? null,
                'assessment_version' => $payload['assessment_version'] ?? null,
                'algorithm_version' => $data['algorithm_version'] ?? null,
                'http_status' => $status,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ],
        );

        return $data;
    }

    private function safeJson(Response $response): array
    {
        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    private function logFailure(
        array $payload,
        string $requestId,
        ?int $status,
        float $startedAt,
        string $reason
    ): void {
        Log::warning(
            'Baseline intelligence request failed.',
            [
                'request_id' => $requestId,
                'student_profile_id' => $payload['student_profile_id'] ?? null,
                'assessment_version' => $payload['assessment_version'] ?? null,
                'http_status' => $status,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'failure_reason' => $reason,
            ],
        );
    }
}
