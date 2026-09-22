<?php

namespace App\Services\Assistant;

use App\Exceptions\AssistantException;
use App\Services\Intelligence\IntelligenceClient;
use Illuminate\Support\Facades\Log;

/**
 * US-REC-01 — FastAPI assistant client.
 *
 * ⚠️ AWAITING THE FASTAPI CONTRACT.
 *
 * No assistant endpoint exists in any approved source: not in SRS v1.1,
 * not in SkillSpan_New_Endpoints.pdf, and not in the data-science-service
 * tree. The request/response shape is therefore deliberately NOT guessed —
 * inventing it would break §12.5 and REC-07 ("does not fabricate
 * evidence"), and would produce a client that silently misreads whatever
 * the service actually returns.
 *
 * What IS final and enforced here is the part that must never depend on
 * the contract: the §12.5 governance gate. It fails closed, before any
 * network I/O, so a misconfiguration can never result in learner context
 * leaving the platform.
 *
 * When the Data Science team publishes the contract, implement
 * {@see ask()} — the transport should mirror
 * {@see IntelligenceClient::post()} exactly
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
         * The contract boundary. This is the ONE place to change once the
         * assistant endpoint is agreed; the failure code is stable and
         * actionable so the endpoint reports "not wired yet" rather than
         * fabricating an answer (REC-07, AC-16 equivalent).
         */
        throw new AssistantException(
            'The FastAPI assistant contract has not been agreed yet.',
            503,
            'ASSISTANT_CONTRACT_PENDING',
            [
                'awaiting' => 'assistant endpoint path and request/response schema',
                'owner' => 'Data Science team',
            ],
        );
    }
}
