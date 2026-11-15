<?php

namespace App\Services\Intelligence;

use App\Exceptions\IntelligenceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class IntelligenceClient
{
    private readonly int $timeout;

    public function __construct(
        private readonly ?string $serviceToken = null,
        private readonly ?string $baseUrl = null,
        ?int $timeout = null,
    ) {
        $this->timeout = ($timeout !== null && $timeout > 0)
            ? $timeout
            : (int) config('services.data_science.timeout', 60);
    }

    private function resolvedBaseUrl(): string
    {
        return rtrim((string) ($this->baseUrl ?? config('services.data_science.url')), '/');
    }

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

    public function toRoadmapRequest(array $payload): array
    {
        $learner = $payload['learner'] ?? [];
        $role = $payload['role'] ?? [];

        $skills = [];

        foreach ($payload['skills'] ?? [] as $skill) {
            $skills[] = [
                'skill_id' => (int) $skill['skill_id'],
                'skill_name' => (string) $skill['skill_name'],
                'current_level' => (float) $skill['current_level'],
                'required_level' => (float) $skill['required_level'],
                'importance_weight' => (float) $skill['importance_weight'],
                'is_critical' => (bool) $skill['is_critical'],
                'confidence' => (float) $skill['confidence'],
                'prerequisite_skill_ids' => array_values(array_map(
                    static fn ($id): int => (int) $id,
                    $skill['prerequisite_skill_ids'] ?? [],
                )),
            ];
        }

        return [
            'learner' => [
                'student_profile_id' => (int) $learner['student_profile_id'],
                'user_id' => (int) $learner['user_id'],
                'availability' => $learner['availability'] ?? null,
                'weekly_availability_hours' => $learner['weekly_availability_hours'] ?? null,
            ],
            'role' => [
                'id' => (int) $role['id'],
                'title' => (string) $role['title'],
                'version' => (int) $role['version'],
            ],
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

        return $this->post(
            (string) config('services.data_science.roadmap_path', '/api/v1/roadmap'),
            $this->toRoadmapRequest($payload),
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
