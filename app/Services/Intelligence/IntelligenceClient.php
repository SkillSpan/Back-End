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
     * @param  non-empty-string  $path  Contract path, e.g. /api/v1/skill-gap
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
            (string) config('services.data_science.skill_gap_path', '/api/v1/skill-gap'),
            $this->toSkillGapRequest($payload),
            $requestId,
            'skill_gap',
        );
    }

    /**
     * Map the canonical internal payload onto the deployed
     * `SkillGapRequest` contract (POST /api/v1/skill-gap).
     *
     * The internal payload is nested (`learner` / `role`) because it is
     * also the decision-snapshot record and the reference the response
     * validator checks identity against. The deployed contract is FLAT,
     * so the two cannot be the same array.
     *
     * Only fields the contract declares are sent: we do not rely on the
     * service's model being configured to ignore unknown keys, because
     * that is a deployment detail we do not control.
     *
     * @param  array<string, mixed>  $payload  canonical nested payload
     * @return array<string, mixed> the flat SkillGapRequest body
     */
    private function toSkillGapRequest(array $payload): array
    {
        $skills = [];

        foreach ($payload['skills'] ?? [] as $skill) {
            $skills[] = [
                'skill_id' => (int) $skill['skill_id'],
                'skill_name' => (string) $skill['skill_name'],
                'current_level' => (float) $skill['current_level'],
                'required_level' => (float) $skill['required_level'],
                'importance_weight' => (float) $skill['importance_weight'],
                'is_critical' => (bool) $skill['is_critical'],
            ];
        }

        return [
            'student_profile_id' => (int) $payload['learner']['student_profile_id'],
            'career_role_id' => (int) $payload['role']['id'],
            'career_role_version' => (int) $payload['role']['version'],
            'user_id' => (int) $payload['learner']['user_id'],
            'target_role' => (string) $payload['role']['title'],
            'skills' => $skills,
        ];
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

        /*
         * UNVERIFIED PATH. The deployed service exposes no roadmap endpoint
         * in any form, so unlike skill-gap this cannot be confirmed against
         * a live contract. The body is Laravel's nested internal payload,
         * also unconfirmed. Enabling roadmap generation without first
         * confirming both against the service will fail.
         */
        return $this->post(
            (string) config('services.data_science.roadmap_path', '/api/v1/roadmap'),
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

        Log::info('Intelligence service request completed.', array_merge(
            $this->correlationContext($payload),
            [
                'request_id' => $requestId,
                'operation' => $operation,
                'algorithm_version' => $data['algorithm_version'] ?? null,
                'http_status' => $status,
                'duration_ms' => $durationMs,
            ],
        ));

        return $data;
    }

    private function safeJson(Response $response): array
    {
        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * Correlation identifiers for structured logging.
     *
     * post() is shared by endpoints with different body shapes: the
     * skill-gap call sends the deployed FLAT SkillGapRequest, while the
     * (unverified) roadmap call still sends Laravel's nested internal
     * payload. Reading both shapes keeps the correlation fields
     * populated instead of silently logging nulls.
     *
     * @return array<string, int|string|null>
     */
    private function correlationContext(array $payload): array
    {
        return [
            'student_profile_id' => $payload['student_profile_id']
                ?? $payload['learner']['student_profile_id']
                ?? null,
            'career_role_id' => $payload['career_role_id']
                ?? $payload['role']['id']
                ?? null,
            'career_role_version' => $payload['career_role_version']
                ?? $payload['role']['version']
                ?? null,
        ];
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
        Log::warning('Intelligence service request failed.', array_merge(
            $this->correlationContext($payload),
            [
                'request_id' => $requestId,
                'operation' => $operation,
                'http_status' => $status,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'failure_reason' => $reason,
            ],
        ));
    }
}
