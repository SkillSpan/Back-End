<?php

namespace App\Services\Assistant;

use App\Exceptions\AssistantException;
use App\Services\Intelligence\IntelligenceClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * US-REC-01 — assistant service client.
 *
 * Transport for the FastAPI chatbot service at `E:\SkillSpan\chatbot`:
 *
 *     POST /chat   { user_id, message, context? }
 *              -> { reply, provider_used, timestamp, prompt_version }
 *     GET  /health -> { status, providers, auth_enabled, retrieval }
 *
 * Laravel is the ONLY permitted caller. The service requires a bearer token on
 * every /chat and allows no browser origins, so the frontend cannot reach it —
 * which is what makes the §12.5 gate below the single door rather than one of
 * two. A gate on one of two doors is not a gate.
 *
 * The transport mirrors {@see IntelligenceClient::post()} deliberately: the same
 * credential handling, the same X-Request-ID correlation, the same failure
 * taxonomy, and the same rule that nothing is ever fabricated. Two integrations
 * that both talk to FastAPI should not fail in two different vocabularies.
 *
 * ⚠️ `provider_used === null` is a SOFT failure, not a success. The service
 * returns HTTP 200 with a fallback message when every provider in its failover
 * chain fails, so the status code cannot distinguish "answered" from "nobody
 * answered". {@see AssistantService::ask()} branches on it.
 */
class AssistantClient
{
    /**
     * Configured request timeout in seconds. Declared, typed, and assigned
     * exactly once in the constructor — never a dynamic property.
     */
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

    /**
     * SRS v1.1 §12.5 — AI Assistant Boundaries:
     *
     *   "Sensitive data shall not be inserted into external AI services
     *    without explicit technical and governance approval."
     *
     * Fails closed. The assistant stays disabled unless it is explicitly
     * enabled AND a reference to the recorded approval is present. This
     * mirrors the approved pattern the SRS already uses for collaborative
     * signals (REC-06 / BR-REC-07): off by default, and not enableable
     * without approval metadata.
     *
     * @throws AssistantException
     */
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

    /**
     * Ask the assistant service one question about an approved context snapshot.
     *
     * @param  string  $userId  The learner's identifier. The service logs it for
     *                          correlation and never puts it in the prompt.
     * @param  array<string, mixed>  $contextSnapshot  The approved snapshot from
     *                                                 {@see AssistantContextBuilder::build()}.
     * @return array{reply: string, provider_used: string|null, prompt_version: string|null, timestamp: string|null}
     *
     * @throws AssistantException
     */
    public function ask(
        string $userId,
        string $message,
        array $contextSnapshot,
        string $requestId,
    ): array {
        // §12.5 first — never reach the network without approval. The service
        // asserts this too, so the transport cannot be reached without approval
        // even when called directly.
        $this->assertGovernanceApproval();

        // Both fail locally, before any network I/O, when misconfigured.
        $baseUrl = $this->resolvedBaseUrl();
        $token = $this->resolvedServiceToken();

        $payload = [
            'user_id' => $userId,
            'message' => $message,
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

    /**
     * The dedicated service credential. Mandatory on every Laravel -> assistant
     * request; never a learner Sanctum token. A missing token fails locally
     * before any network I/O — the service is never called unauthenticated.
     *
     * @return non-empty-string
     */
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

    /**
     * Render the approved snapshot as the `context` string the service expects.
     *
     * JSON rather than prose, deliberately. The snapshot is structured decision
     * data, and a hand-written prose rendering would be a second, unreviewed
     * description of the learner's record — exactly the kind of paraphrase
     * REC-07 forbids. The label tells the model where the data starts.
     *
     * @param  array<string, mixed>  $snapshot
     */
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

    /**
     * @return array{reply: string, provider_used: string|null, prompt_version: string|null, timestamp: string|null}
     */
    private function parseResponse(Response $response, string $requestId, float $startedAt): array
    {
        $status = $response->status();
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($status === 401 || $status === 403) {
            /*
             * The service rejected *our* credential, not the learner's. This is
             * a deployment fault: ASSISTANT_SERVICE_TOKEN and the service's own
             * SERVICE_TOKEN must be the same value. Surfacing it as a 503 rather
             * than a 401 keeps the distinction honest — the learner's session is
             * fine, the integration is misconfigured.
             */
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
            // The service reports 503 when its own SERVICE_TOKEN is unset, so
            // every /chat is refused at the far end.
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

        // A 200 without a reply is not a usable answer, and treating it as one
        // would hand the learner an empty string where an explanation belongs.
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
            // Deliberately no question, no reply and no context snapshot.
            // §12.5 data minimisation applies to logs as much as to the database:
            // a log line is still a copy of learner content on a third party's
            // infrastructure.
        ]);

        return [
            'reply' => $data['reply'],
            'provider_used' => $providerUsed,
            'prompt_version' => isset($data['prompt_version']) && is_string($data['prompt_version'])
                ? $data['prompt_version']
                : null,
            'timestamp' => isset($data['timestamp']) && is_string($data['timestamp'])
                ? $data['timestamp']
                : null,
        ];
    }

    private function safeJson(Response $response): array
    {
        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * Structured failure logging with correlation data only — no secrets, no
     * tokens, no question, no reply, no context snapshot.
     *
     * Note the deliberate absence of a `$payload` argument, unlike
     * {@see IntelligenceClient::logFailure()}. That client logs identifiers from
     * its payload for correlation; here the payload *is* the learner's record,
     * so it must never reach a log line.
     */
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
