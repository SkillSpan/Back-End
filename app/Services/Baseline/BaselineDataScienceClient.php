<?php

namespace App\Services\Baseline;

use App\Exceptions\BaselineAssessmentException;
use App\Models\StudentProfile;
use App\Support\SafeLog;
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
 * Expected contract (the FastAPI `BaselineRequest` schema is the source of
 * truth; see App\Services\Baseline\BaselineIntelligencePayloadBuilder):
 *
 * POST {url}/api/v1/baseline
 *
 * Request:
 * {
 *   "student_profile_id": 15,
 *   "user_id": 20,
 *   "career_role_id": 3,
 *   "assessment_version": "v1.0",
 *   "questions": [
 *     {
 *       "question_id": 27,
 *       "question_type": "single_choice",
 *       "options": ["A", "B", "C", "D"],
 *       "correct_answer": "A",
 *       "scoring_rule": null,
 *       "weight": 1.0,
 *       "skill_mappings": [
 *         { "skill_id": 7, "skill_slug": "sql", "weight": 1.0 }
 *       ]
 *     }
 *   ],
 *   "responses": [
 *     { "question_id": 27, "answer": "A" }
 *   ]
 * }
 *
 * Identifier note:
 * `questions[].question_id` and `responses[].question_id` are the SAME
 * numeric `baseline_assessment_item_id`. The public/client identifier
 * (`item_id`, e.g. "sql-001") never leaves Laravel — it is translated while
 * the payload is built from the frozen snapshot.
 *
 * Response 200:
 * {
 *   "algorithm_version": "baseline-v2.0",
 *   "assessment_version": "v1.0",
 *   "career_role_id": 3,
 *   "student_profile_id": 15,
 *   "overall_score": 87.5,
 *   "skills": [
 *     { "skill_id": 7, "slug": "sql", "level": 4.38, "confidence": 1.0 }
 *   ]
 * }
 *
 * `algorithm_version` is mandatory. `skills` must be non-empty. When
 * `skill_id` is present it MUST equal the id of the skill named by `slug`;
 * a disagreement is rejected by the caller as INTELLIGENCE_SKILL_MISMATCH.
 * Every returned skill must belong to the request's career role, or it is
 * rejected as INTELLIGENCE_SKILL_OUT_OF_SCOPE.
 */
class BaselineDataScienceClient
{
    /**
     * @param  array<int, array<string, mixed>>  $questions  FastAPI `questions[]`, already frozen.
     * @param  array<int, array<string, mixed>>  $responses  FastAPI `responses[]` with integer question_id.
     */
    public function compute(
        StudentProfile $studentProfile,
        string $assessmentVersion,
        array $questions,
        array $responses,
        string $requestId,
        ?int $careerRoleId = null,
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
            (string) config(
                'services.data_science.baseline.path',
                'api/v1/baseline'
            ),
            '/'
        );

        // The configured Data Science timeout (60s by default) — Render cold
        // starts measure 24.7-33.8s, so a short fallback would turn a healthy
        // service into a false outage.
        $timeout = (int) config(
            'services.data_science.timeout',
            60
        );

        $endpoint = $baseUrl.'/'.$path;

        // US-INT-01 §4: dedicated service credential, mandatory on every
        // Laravel -> FastAPI call — never a learner Sanctum token.
        $serviceToken = trim(
            (string) config('services.data_science.service_token', '')
        );

        if ($serviceToken === '') {
            throw new BaselineAssessmentException(
                'The intelligence service token is not configured.',
                503,
                'BASELINE_INTEGRATION_NOT_CONFIGURED',
            );
        }

        // Last line of defence: the builder already validated the full
        // contract, but a request with no questions/responses (or without the
        // required career_role_id) must never reach the wire.
        if ($questions === [] || $responses === [] || $careerRoleId === null) {
            $problems = [];

            if ($questions === []) {
                $problems[] = 'questions';
            }

            if ($responses === []) {
                $problems[] = 'responses';
            }

            if ($careerRoleId === null) {
                $problems[] = 'career_role_id';
            }

            throw BaselineAssessmentException::invalidIntelligencePayload($problems);
        }

        // Exactly the fields of the FastAPI BaselineRequest — no more.
        $payload = [
            'student_profile_id' => (int) $studentProfile->id,
            'user_id' => (int) $studentProfile->user_id,
            'career_role_id' => (int) $careerRoleId,
            'assessment_version' => $assessmentVersion,
            'questions' => $questions,
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
                ->withToken($serviceToken)
                ->post($endpoint, $payload);
        } catch (ConnectionException $e) {
            $this->logFailure(
                $payload,
                $requestId,
                null,
                $startedAt,
                $e->getMessage()
            );

            throw new BaselineAssessmentException(
                'The intelligence service is unavailable or timed out.',
                503,
                'INTELLIGENCE_SERVICE_UNAVAILABLE',
                [],
                $e,
            );
        } catch (Throwable $e) {
            $this->logFailure(
                $payload,
                $requestId,
                null,
                $startedAt,
                $e->getMessage()
            );

            throw new BaselineAssessmentException(
                'The intelligence integration failed unexpectedly.',
                502,
                'INTELLIGENCE_INTEGRATION_FAILED',
                [],
                $e,
            );
        }

        return $this->parseResponse(
            $response,
            $payload,
            $requestId,
            $startedAt
        );
    }

    private function parseResponse(
        Response $response,
        array $payload,
        string $requestId,
        float $startedAt
    ): array {
        $status = $response->status();

        if ($status === 422) {
            // The FastAPI validation body echoes the submitted input values —
            // i.e. the learner's answers. It is summarised (bounded, and only
            // the service's own `detail` string is kept) for the log, and is
            // never copied into the client-facing error.
            $this->logFailure(
                $payload,
                $requestId,
                $status,
                $startedAt,
                'Intelligence validation error: '.$this->bodyExcerpt($response),
            );

            throw new BaselineAssessmentException(
                'The intelligence service rejected the baseline request.',
                422,
                'INTELLIGENCE_VALIDATION_ERROR',
            );
        }

        if ($status >= 500) {
            // Never let an upstream 5xx become a black box.
            //
            // The response body is the only place the service explains
            // itself. FastAPI answers a 503 with a plain
            // {"detail": "..."} — and discarding that detail is exactly what
            // turned a one-line configuration problem into an opaque
            // "intelligence service failed to process the request".
            //
            // The body is logged, not returned: the public response shape
            // stays as it was, and the service token travels in a request
            // header that the intelligence service never echoes back.
            $this->logFailure(
                $payload,
                $requestId,
                $status,
                $startedAt,
                'Intelligence server error: '.$this->bodyExcerpt($response),
            );

            throw new BaselineAssessmentException(
                'The intelligence service failed to process the request.',
                503,
                'INTELLIGENCE_SERVICE_ERROR',
            );
        }

        if (! $response->successful()) {
            $this->logFailure(
                $payload,
                $requestId,
                $status,
                $startedAt,
                'Unexpected intelligence HTTP status'
            );

            throw new BaselineAssessmentException(
                'The intelligence service returned an unexpected HTTP status.',
                502,
                'INTELLIGENCE_UNEXPECTED_STATUS',
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            $this->logFailure(
                $payload,
                $requestId,
                $status,
                $startedAt,
                'Invalid JSON response'
            );

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
                'duration_ms' => (int) round(
                    (microtime(true) - $startedAt) * 1000
                ),
            ],
        );

        return $data;
    }

    /**
     * A bounded, single-line summary of the upstream failure body, for the log
     * only — never the body itself.
     *
     * The body comes from a different service, so it is untrusted text: on a
     * 5xx it can be a debug page or traceback that echoes the request, and the
     * request here is the learner's own record. {@see SafeLog::upstreamBody()}
     * keeps only the service's documented `{"detail": "..."}` explanation and
     * summarises anything else by size. Bounded so a pathological response
     * cannot flood the log, and collapsed to one line so the JSON stays
     * greppable next to the request id.
     */
    private function bodyExcerpt(Response $response): string
    {
        return SafeLog::upstreamBody((string) $response->body());
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
                'duration_ms' => (int) round(
                    (microtime(true) - $startedAt) * 1000
                ),
                'failure_reason' => $reason,
            ],
        );
    }
}
