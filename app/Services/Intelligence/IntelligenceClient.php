<?php

namespace App\Services\Intelligence;

use App\Exceptions\IntelligenceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * US-INT-01 — versioned, authenticated FastAPI intelligence client.
 *
 * Every request carries the service credential (never a learner
 * Sanctum token), the correlation X-Request-ID, the configured
 * timeout, and the versioned SRS paths. All failure modes map to
 * stable INTELLIGENCE_* error codes; nothing is ever fabricated.
 */
class IntelligenceClient
{
    /**
     * Configured request timeout in seconds. Declared, typed, and
     * assigned exactly once in the constructor — never a dynamic
     * property.
     */
    private readonly int $timeout;

    public function __construct(
        private readonly ?string $serviceToken = null,
        private readonly ?string $baseUrl = null,
        int $timeout = 10,
    ) {
        $this->timeout = $timeout > 0
            ? $timeout
            : (int) config('services.data_science.timeout', 10);
    }

    private function resolvedBaseUrl(): string
    {
        return rtrim((string) ($this->baseUrl ?? config('services.data_science.url')), '/');
    }

    /**
     * The dedicated service credential (US-INT-01 §4). Mandatory on
     * every Laravel -> FastAPI intelligence request; never a learner
     * Sanctum token. A missing token fails locally before any network
     * I/O — FastAPI is never called unauthenticated.
     *
     * @return non-empty-string
     */
    private function resolvedServiceToken(): string
    {
        $token = $this->serviceToken ?? config('services.data_science.service_token');

        if (! is_string($token) || trim($token) === '') {
            throw new IntelligenceException(
                'The intelligence service token is not configured.',
                503,
                'INTELLIGENCE_NOT_CONFIGURED',
            );
        }

        return trim($token);
    }

    /**
     * Send one intelligence request and return the decoded JSON body.
     *
     * @param  non-empty-string  $path  Versioned SRS path, e.g. /api/v1/intelligence/skill-gap
     */
    public function post(string $path, array $payload, string $requestId, string $operation): array
    {
        $baseUrl = $this->resolvedBaseUrl();

        if ($baseUrl === '') {
            throw new IntelligenceException(
                'The intelligence service URL is not configured.',
                503,
                'INTELLIGENCE_NOT_CONFIGURED',
            );
        }

        $startedAt = microtime(true);

        // Fail locally before any network I/O when the mandatory
        // service credential is missing.
        $token = $this->resolvedServiceToken();

        try {
            $request = Http::acceptJson()
                ->contentType('application/json')
                ->timeout($this->timeout)
                ->withHeaders([
                    'X-Request-ID' => $requestId,
                ])
                ->withToken($token);

            $response = $request->post($baseUrl.$path, $payload);
        } catch (ConnectionException $e) {
            $this->logFailure($requestId, $operation, null, $startedAt, 'connection failure / timeout', $payload);

            throw new IntelligenceException(
                'The intelligence service is unavailable or timed out.',
                503,
                'INTELLIGENCE_UNAVAILABLE',
                [],
                $e,
            );
        } catch (Throwable $e) {
            $this->logFailure($requestId, $operation, null, $startedAt, 'unexpected transport failure', $payload);

            throw new IntelligenceException(
                'The intelligence service request failed unexpectedly.',
                502,
                'INTELLIGENCE_CALCULATION_FAILED',
                [],
                $e,
            );
        }

        return $this->parseResponse($response, $requestId, $operation, $startedAt, $payload);
    }

    public function calculateSkillGap(array $payload, string $requestId): array
    {
        return $this->post(
            (string) config('services.data_science.skill_gap_path', '/api/v1/intelligence/skill-gap'),
            $payload,
            $requestId,
            'skill_gap',
        );
    }

    public function calculateReadiness(array $payload, string $requestId): array
    {
        return $this->post(
            (string) config('services.data_science.readiness_path', '/api/v1/intelligence/readiness'),
            $payload,
            $requestId,
            'readiness',
        );
    }

    public function generateRoadmap(array $payload, string $requestId): array
    {
        if (! config('services.data_science.roadmap_enabled', false)) {
            throw new IntelligenceException(
                'Roadmap generation is not enabled for this intelligence service.',
                503,
                'INTELLIGENCE_NOT_CONFIGURED',
            );
        }

        return $this->post(
            (string) config('services.data_science.roadmap_path', '/api/v1/intelligence/roadmap'),
            $payload,
            $requestId,
            'roadmap',
        );
    }

    private function parseResponse(
        Response $response,
        string $requestId,
        string $operation,
        float $startedAt,
        array $payload,
    ): array {
        $status = $response->status();
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($status === 422) {
            $this->logFailure($requestId, $operation, $status, $startedAt, 'service validation error', $payload);

            throw new IntelligenceException(
                'The intelligence service rejected the request.',
                422,
                'INTELLIGENCE_VALIDATION_ERROR',
                $this->safeJson($response),
            );
        }

        if ($status >= 500) {
            $this->logFailure($requestId, $operation, $status, $startedAt, 'service server error', $payload);

            throw new IntelligenceException(
                'The intelligence service failed to process the request.',
                503,
                'INTELLIGENCE_UNAVAILABLE',
            );
        }

        if (! $response->successful()) {
            $this->logFailure($requestId, $operation, $status, $startedAt, 'unexpected service status', $payload);

            throw new IntelligenceException(
                'The intelligence service returned an unexpected response.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['http_status' => $status],
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            $this->logFailure($requestId, $operation, $status, $startedAt, 'invalid JSON response', $payload);

            throw new IntelligenceException(
                'The intelligence service returned an invalid JSON response.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        Log::info('Intelligence service request completed.', [
            'request_id' => $requestId,
            'operation' => $operation,
            'student_profile_id' => $payload['learner']['student_profile_id'] ?? null,
            'career_role_id' => $payload['role']['id'] ?? null,
            'career_role_version' => $payload['role']['version'] ?? null,
            'algorithm_version' => $data['algorithm_version'] ?? null,
            'http_status' => $status,
            'duration_ms' => $durationMs,
        ]);

        return $data;
    }

    private function safeJson(Response $response): array
    {
        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * Structured failure logging with correlation data only — no
     * secrets, no tokens, no evidence contents.
     */
    private function logFailure(
        string $requestId,
        string $operation,
        ?int $status,
        float $startedAt,
        string $reason,
        array $payload,
    ): void {
        Log::warning('Intelligence service request failed.', [
            'request_id' => $requestId,
            'operation' => $operation,
            'student_profile_id' => $payload['learner']['student_profile_id'] ?? null,
            'career_role_id' => $payload['role']['id'] ?? null,
            'career_role_version' => $payload['role']['version'] ?? null,
            'http_status' => $status,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'failure_reason' => $reason,
        ]);
    }
}
