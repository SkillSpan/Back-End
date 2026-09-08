<?php

namespace App\Services\Readiness;

use App\Exceptions\ReadinessIntegrationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class DataScienceClient
{
    public function analyze(array $payload, string $requestId): array
    {
        $baseUrl = rtrim(
            (string) config('services.data_science.url'),
            '/'
        );

        $timeout = (int) config(
            'services.data_science.timeout',
            10
        );

        $endpoint = $baseUrl.'/api/v1/skill-gap';
        $startedAt = microtime(true);

        if ($baseUrl === '') {
            throw new ReadinessIntegrationException(
                'Data Science service URL is not configured.',
                503,
                'DATA_SCIENCE_NOT_CONFIGURED',
            );
        }

        try {
            $response = Http::acceptJson()
                ->contentType('application/json')
                ->timeout($timeout)
                ->withHeaders([
                    'X-Request-ID' => $requestId,
                ])
                ->post($endpoint, $payload);
        } catch (ConnectionException $e) {
            $this->logFailure(
                $payload,
                $requestId,
                null,
                $startedAt,
                $e->getMessage(),
            );

            throw new ReadinessIntegrationException(
                'The Data Science service is unavailable or timed out.',
                503,
                'DATA_SCIENCE_UNAVAILABLE',
                [],
                $e,
            );
        } catch (Throwable $e) {
            $this->logFailure(
                $payload,
                $requestId,
                null,
                $startedAt,
                $e->getMessage(),
            );

            throw new ReadinessIntegrationException(
                'The Data Science integration failed unexpectedly.',
                502,
                'DATA_SCIENCE_INTEGRATION_FAILED',
                [],
                $e,
            );
        }

        return $this->parseResponse(
            $response,
            $payload,
            $requestId,
            $startedAt,
        );
    }

    private function parseResponse(
        Response $response,
        array $payload,
        string $requestId,
        float $startedAt,
    ): array {
        $durationMs = (int) round(
            (microtime(true) - $startedAt) * 1000
        );

        $status = $response->status();

        if ($status === 422) {
            $this->logFailure(
                $payload,
                $requestId,
                $status,
                $startedAt,
                'FastAPI validation error',
            );

            throw new ReadinessIntegrationException(
                'The Data Science service rejected the request.',
                422,
                'DATA_SCIENCE_VALIDATION_ERROR',
                $this->safeJson($response),
            );
        }

        if ($status >= 500) {
            $this->logFailure(
                $payload,
                $requestId,
                $status,
                $startedAt,
                'FastAPI server error',
            );

            throw new ReadinessIntegrationException(
                'The Data Science service failed to process the request.',
                503,
                'DATA_SCIENCE_SERVICE_ERROR',
            );
        }

        if (! $response->successful()) {
            $this->logFailure(
                $payload,
                $requestId,
                $status,
                $startedAt,
                'Unexpected FastAPI HTTP status',
            );

            throw new ReadinessIntegrationException(
                'The Data Science service returned an unexpected HTTP status.',
                502,
                'DATA_SCIENCE_UNEXPECTED_STATUS',
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            $this->logFailure(
                $payload,
                $requestId,
                $status,
                $startedAt,
                'Invalid JSON response',
            );

            throw new ReadinessIntegrationException(
                'The Data Science service returned an invalid JSON response.',
                502,
                'DATA_SCIENCE_INVALID_RESPONSE',
            );
        }

        Log::info(
            'Data Science readiness request completed.',
            [
                'request_id' => $requestId,
                'student_profile_id' => $payload['student_profile_id'] ?? null,
                'career_role_id' => $payload['career_role_id'] ?? null,
                'career_role_version' => $payload['career_role_version'] ?? null,
                'algorithm_version' => $data['algorithm_version'] ?? null,
                'http_status' => $status,
                'duration_ms' => $durationMs,
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
        string $reason,
    ): void {
        Log::warning(
            'Data Science readiness request failed.',
            [
                'request_id' => $requestId,
                'student_profile_id' => $payload['student_profile_id'] ?? null,
                'career_role_id' => $payload['career_role_id'] ?? null,
                'career_role_version' => $payload['career_role_version'] ?? null,
                'algorithm_version' => $payload['algorithm_version'] ?? null,
                'http_status' => $status,
                'duration_ms' => (int) round(
                    (microtime(true) - $startedAt) * 1000
                ),
                'failure_reason' => $reason,
            ],
        );
    }
}
