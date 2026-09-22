<?php

namespace App\Services\Assistant;

use App\Exceptions\AssistantException;
use App\Services\Intelligence\IntelligenceClient;
use Illuminate\Support\Facades\Log;

/**
 * US-REC-01 — assistant service client.
 *
 * ⚠️ NOT WIRED YET — and the reason is NOT a missing contract.
 *
 * Correction: an earlier revision of this docblock claimed no assistant
 * endpoint existed in any source. That was wrong — it was a search
 * failure. The service exists at `E:\SkillSpan\chatbot` (FastAPI):
 *
 *     POST /chat   { user_id, message, context? }
 *              -> { reply, provider_used, timestamp }
 *     GET  /health -> { status: "ok" }
 *
 * It fails over Gemini -> Groq -> Cerebras, returns 422 on validation,
 * and returns 200 with a fallback reply when every provider fails.
 *
 * What is still genuinely open is the *integration* contract, and it is a
 * decision for the team rather than for this class:
 *
 *   1. The service is grounded on SkillSpan DOCUMENTATION (`context` is a
 *      RAG blob), and has no field for the learner's own data. US-REC-01
 *      needs the learner's readiness/gaps/roadmap explained, so either
 *      Laravel composes that data into the prompt — which means personal
 *      data reaches three external LLM providers, exactly what §12.5
 *      governs — or the service gains a field for it.
 *   2. The response carries `reply`, but this endpoint currently relays
 *      no reply text to the client. See the review for the full list.
 *
 * Full analysis: `.workbuddy-ai/chatbot-integration-review.md`.
 *
 * What IS final and enforced here is the part that must never depend on
 * the contract: the §12.5 governance gate. It fails closed, before any
 * network I/O, so a misconfiguration can never result in learner context
 * leaving the platform.
 *
 * Once the integration is agreed, implement {@see ask()} — the transport
 * should mirror {@see IntelligenceClient::post()} exactly
 * (service token, X-Request-ID, configured timeout, the same failure
 * taxonomy). Nothing else in this class needs to change.
 */
class AssistantClient
{
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
     * Ask the assistant service about one approved context snapshot.
     *
     * @param  array<string, mixed>  $contextSnapshot
     * @return array<string, mixed>
     *
     * @throws AssistantException
     */
    public function ask(array $contextSnapshot, string $question, string $requestId): array
    {
        // §12.5 first — never reach the network without approval.
        $this->assertGovernanceApproval();

        /*
         * The integration boundary. The service and its HTTP shape are
         * known; what is not yet agreed is how the learner's own data
         * reaches it without breaching §12.5. The code stays stable and
         * actionable so the endpoint reports "not wired yet" rather than
         * fabricating an answer (REC-07).
         */
        throw new AssistantException(
            'The assistant service is not wired yet.',
            503,
            'ASSISTANT_CONTRACT_PENDING',
            [
                'awaiting' => 'the decision on how learner context reaches the assistant service',
                'owner' => 'Backend + Data Science',
                'service' => 'E:\SkillSpan\chatbot (POST /chat)',
            ],
        );
    }
}
