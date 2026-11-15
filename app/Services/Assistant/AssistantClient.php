<?php

namespace App\Services\Assistant;

use App\Exceptions\AssistantException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssistantClient
{
    private readonly int $timeout;

    public function __construct(
        private readonly ?string $serviceToken = null,
        private readonly ?string $baseUrl = null,
        int $timeout = 0,
    ) {
        $this->timeout = $timeout > 0
            ? $timeout
            : (int) config('services.assistant.timeout', 60);
    }

    public function assertGovernanceApproval(): void
    {
        if (! config('services.assistant.enabled', false)) {
            Log::warning('Assistant request refused: §12.5 gate closed (not enabled).');

            throw new AssistantException(
                'The assistant is not enabled.',
                503,
                'ASSISTANT_NOT_ENABLED',
            );
        }

        $approval = config('services.assistant.approval_reference');

        if (! is_string($approval) || trim($approval) === '') {
            Log::warning('Assistant request refused: §12.5 gate closed (no recorded approval).');

            throw new AssistantException(
                'The assistant is enabled without a recorded §12.5 governance approval.',
                503,
                'ASSISTANT_APPROVAL_NOT_RECORDED',
            );
        }
    }

    public function ask(
        string $userId,
        string $message,
        array $contextSnapshot,
        string $requestId,
        string $intent,
    ): array {
        $this->assertGovernanceApproval();

        $baseUrl = $this->resolvedBaseUrl();
        $token = $this->resolvedServiceToken();

        $payload = [
            'user_id' => $userId,
            'message' => $message,
            'intent' => $intent,
            'context' => $this->serialiseContext($contextSnapshot),
        ];

        $startedAt = microtime(true);

        try {
            $response = Http::acceptJson()
                ->contentType('application/json')
                ->timeout($this->timeout)
                ->withHeaders([
                    'X-Request-ID' => $requestId,
                ])
                ->withToken($token)
                ->post($baseUrl.(string) config('services.assistant.path', '/chat'), $payload);
        } catch (ConnectionException $e) {
            $this->logFailure($requestId, null, $startedAt, 'connection failure / timeout');

            throw new AssistantException(
                'The assistant service is unavailable or timed out.',
                503,
                'ASSISTANT_UNAVAILABLE',
                [],
                $e,
            );
        } catch (Throwable $e) {
            $this->logFailure($requestId, null, $startedAt, 'unexpected transport failure');

            throw new AssistantException(
                'The assistant service request failed unexpectedly.',
                502,
                'ASSISTANT_FAILED',
                [],
                $e,
            );
        }

        return $this->parseResponse($response, $requestId, $startedAt);
    }

    private function resolvedBaseUrl(): string
    {
        $url = rtrim((string) ($this->baseUrl ?? config('services.assistant.url')), '/');

        if ($url === '') {
            throw new AssistantException(
                'The assistant service URL is not configured.',
                503,
                'ASSISTANT_NOT_CONFIGURED',
            );
        }

        return $url;
    }

    private function resolvedServiceToken(): string
    {
        $token = $this->serviceToken ?? config('services.assistant.service_token');

        if (! is_string($token) || trim($token) === '') {
            throw new AssistantException(
                'The assistant service token is not configured.',
                503,
                'ASSISTANT_NOT_CONFIGURED',
            );
        }

        return trim($token);
    }

    private function serialiseContext(array $snapshot): string
    {
        $encoded = json_encode(
            $snapshot,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($encoded === false) {
            throw new AssistantException(
                'The assistant context snapshot could not be encoded.',
                500,
                'ASSISTANT_FAILED',
            );
        }

        return "SkillSpan learner context (authoritative stored values):\n".$encoded;
    }

    private function parseResponse(Response $response, string $requestId, float $startedAt): array
    {
        $status = $response->status();
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($status === 401 || $status === 403) {
            $this->logFailure($requestId, $status, $startedAt, 'service rejected our credential');

            throw new AssistantException(
                'The assistant service rejected this application\'s credential.',
                503,
                'ASSISTANT_NOT_CONFIGURED',
            );
        }

        if ($status === 422) {
            $this->logFailure($requestId, $status, $startedAt, 'service validation error');

            throw new AssistantException(
                'The assistant service rejected the request.',
                422,
                'ASSISTANT_VALIDATION_ERROR',
                $this->safeJson($response),
            );
        }

        if ($status === 503) {
            $this->logFailure($requestId, $status, $startedAt, 'service not configured');

            throw new AssistantException(
                'The assistant service is not configured.',
                503,
                'ASSISTANT_NOT_CONFIGURED',
            );
        }

        if ($status >= 500) {
            $this->logFailure($requestId, $status, $startedAt, 'service server error');

            throw new AssistantException(
                'The assistant service failed to process the request.',
                503,
                'ASSISTANT_UNAVAILABLE',
            );
        }

        if (! $response->successful()) {
            $this->logFailure($requestId, $status, $startedAt, 'unexpected service status');

            throw new AssistantException(
                'The assistant service returned an unexpected response.',
                502,
                'ASSISTANT_INVALID_RESPONSE',
                ['http_status' => $status],
            );
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['reply']) || ! is_string($data['reply'])) {
            $this->logFailure($requestId, $status, $startedAt, 'invalid JSON response');

            throw new AssistantException(
                'The assistant service returned an invalid response.',
                502,
                'ASSISTANT_INVALID_RESPONSE',
            );
        }

        $providerUsed = isset($data['provider_used']) && is_string($data['provider_used'])
            ? $data['provider_used']
            : null;

        Log::info('Assistant service request completed.', [
            'request_id' => $requestId,
            'provider_used' => $providerUsed,
            'prompt_version' => $data['prompt_version'] ?? null,
            'http_status' => $status,
            'duration_ms' => $durationMs,
        ]);

        $answerStatus = isset($data['status']) && is_string($data['status'])
            ? $data['status']
            : null;

        return [
            'reply' => $data['reply'],
            'provider_used' => $providerUsed,
            'prompt_version' => isset($data['prompt_version']) && is_string($data['prompt_version'])
                ? $data['prompt_version']
                : null,
            'timestamp' => isset($data['timestamp']) && is_string($data['timestamp'])
                ? $data['timestamp']
                : null,
            'answer_status' => $answerStatus,
            'grounded' => ($data['grounded'] ?? false) === true,
        ];
    }

    private function safeJson(Response $response): array
    {
        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    private function logFailure(
        string $requestId,
        ?int $status,
        float $startedAt,
        string $reason,
    ): void {
        Log::warning('Assistant service request failed.', [
            'request_id' => $requestId,
            'http_status' => $status,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'failure_reason' => $reason,
        ]);
    }
}
