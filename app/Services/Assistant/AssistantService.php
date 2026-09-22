<?php

namespace App\Services\Assistant;

use App\Exceptions\AssistantException;
use App\Models\AssistantInteraction;
use App\Models\StudentProfile;
use App\Services\Intelligence\DecisionSnapshotService;
use App\Services\Intelligence\IntelligenceService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * US-REC-01 — assistant orchestration, mirroring
 * {@see IntelligenceService}:
 *
 *  1. refuse an intent outside the permitted assistant scope (§12.5, BR-01)
 *  2. assert the §12.5 governance gate — before any learner data is read
 *  3. verify the requested context really belongs to this learner (BR-11)
 *  4. build the approved context snapshot (no fabrication, no recomputation)
 *  5. persist the interaction as PENDING (pre-call)
 *  6. call FastAPI through the §12.5-gated client
 *  7. persist SUCCEEDED, or FAILED with a stable failure code
 *
 * Laravel owns authorisation, validation, persistence and routing. It does
 * NOT generate any assistant prose — that is FastAPI's responsibility
 * exclusively, and nothing here fabricates a fallback answer.
 */
class AssistantService
{
    /**
     * The permitted scope of the assistant.
     *
     * SRS v1.1 §12.5 — the assistant may "explain concepts, clarify
     * requirements, ask reflective questions, suggest next steps, and
     * direct the user to approved resources". Anything outside this list is
     * refused at the gateway, before FastAPI is ever contacted.
     *
     * @var list<string>
     */
    public const ALLOWED_INTENTS = [
        'explain_readiness',
        'explain_skill_gap',
        'explain_roadmap',
        'explain_next_best_action',
        'explain_project_recommendation',
        'project_bounded_help',
    ];

    public function __construct(
        private readonly AssistantContextBuilder $contextBuilder,
        private readonly AssistantClient $client,
        private readonly DecisionSnapshotService $snapshotService,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AssistantException
     */
    public function ask(StudentProfile $studentProfile, array $input, string $requestId): AssistantInteraction
    {
        $intent = (string) $input['intent'];

        $this->assertIntentAllowed($intent);

        /*
         * §12.5 is asserted FIRST, before a single learner-owned row is
         * read and before the context snapshot is assembled. The gate is
         * the reason this feature exists in a disabled state at all, so it
         * must fail closed ahead of data *collection*, not merely ahead of
         * transmission — a control that is only consulted after the
         * learner's data has been gathered is not a control.
         *
         * Ordering also keeps the error truthful: a disabled assistant
         * reports ASSISTANT_NOT_ENABLED rather than surfacing whatever
         * downstream subsystem happens to be unconfigured.
         *
         * AssistantClient::ask() re-asserts this, so the transport can
         * never be reached without approval even if called directly.
         */
        $this->client->assertGovernanceApproval();

        /*
         * Targeted context is verified as owned BEFORE it can influence the
         * snapshot. A learner cannot reference another learner's
         * recommendation or project by guessing an id (BR-11 / AC-12), and
         * the lookup deliberately does not distinguish "not found" from
         * "not yours".
         */
        $recommendationId = null;
        $projectId = null;

        if (isset($input['recommendation_id'])) {
            $recommendation = $this->contextBuilder->findOwnedRecommendation(
                $studentProfile,
                (int) $input['recommendation_id'],
            );

            if ($recommendation === null) {
                throw new AssistantException(
                    'The referenced recommendation is not available to this learner.',
                    404,
                    'ASSISTANT_CONTEXT_NOT_PERMITTED',
                );
            }

            $recommendationId = (int) $recommendation->id;
        }

        if (isset($input['project_id'])) {
            $project = $this->contextBuilder->findAccessibleProject(
                $studentProfile,
                (int) $input['project_id'],
            );

            if ($project === null) {
                throw new AssistantException(
                    'The referenced project is not available to this learner.',
                    404,
                    'ASSISTANT_CONTEXT_NOT_PERMITTED',
                );
            }

            $projectId = (int) $project->id;
        }

        $context = $this->contextBuilder->build($studentProfile);

        // Resolved from configuration, never fabricated (REC-01, §8.6).
        $configurationVersion = 'config-v'.$this->snapshotService->resolveConfiguration()->version;

        // PENDING is persisted BEFORE the call so a crash mid-request still
        // leaves an auditable record of the attempt (§12.5, BR-14).
        $interaction = AssistantInteraction::create([
            'student_profile_id' => $studentProfile->id,
            'intent' => $intent,
            'context_reference' => $context['reference'],
            'related_recommendation_id' => $recommendationId,
            'related_project_id' => $projectId,
            'response_status' => AssistantInteraction::STATUS_PENDING,
            'configuration_version' => $configurationVersion,
            'request_id' => $requestId,
        ]);

        try {
            $response = $this->client->ask(
                $context['snapshot'],
                (string) $input['question'],
                $requestId,
            );

            $interaction->update([
                'response_status' => AssistantInteraction::STATUS_SUCCEEDED,
                'algorithm_version' => isset($response['algorithm_version'])
                    ? (string) $response['algorithm_version']
                    : null,
            ]);

            Log::info('Assistant interaction completed.', [
                'request_id' => $requestId,
                'interaction_id' => $interaction->id,
                'student_profile_id' => $studentProfile->id,
                'intent' => $intent,
                'configuration_version' => $configurationVersion,
            ]);

            return $interaction;
        } catch (AssistantException $e) {
            // The attempt is recorded as failed with a stable code. No
            // fabricated answer is persisted or returned (REC-07).
            $interaction->update([
                'response_status' => AssistantInteraction::STATUS_FAILED,
                'failure_code' => $e->codeName,
            ]);

            Log::warning('Assistant interaction failed.', [
                'request_id' => $requestId,
                'interaction_id' => $interaction->id,
                'student_profile_id' => $studentProfile->id,
                'intent' => $intent,
                'failure_code' => $e->codeName,
            ]);

            throw $e;
        } catch (Throwable $e) {
            $interaction->update([
                'response_status' => AssistantInteraction::STATUS_FAILED,
                'failure_code' => 'ASSISTANT_FAILED',
            ]);

            Log::error('Assistant interaction failed unexpectedly.', [
                'request_id' => $requestId,
                'interaction_id' => $interaction->id,
                'student_profile_id' => $studentProfile->id,
                'intent' => $intent,
                'failure_reason' => $e->getMessage(),
            ]);

            throw new AssistantException(
                'The assistant request could not be completed.',
                502,
                'ASSISTANT_FAILED',
                [],
                $e,
            );
        }
    }

    /**
     * §12.6 incident flow / REC-08 — record a learner's report.
     *
     * The lookup is scoped to the authenticated learner's own profile, so
     * one learner can never report (or even confirm the existence of)
     * another learner's interaction (BR-11).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AssistantException
     */
    public function report(StudentProfile $studentProfile, int $interactionId, array $input): AssistantInteraction
    {
        $interaction = AssistantInteraction::query()
            ->whereKey($interactionId)
            ->where('student_profile_id', $studentProfile->id)
            ->first();

        if ($interaction === null) {
            throw new AssistantException(
                'The assistant interaction was not found.',
                404,
                'ASSISTANT_INTERACTION_NOT_FOUND',
            );
        }

        $interaction->update([
            'report_status' => (string) $input['report_status'],
            // §12.6 requires the recorded reason alongside the classification.
            'report_reason' => isset($input['report_reason'])
                ? (string) $input['report_reason']
                : null,
            'reported_at' => now(),
        ]);

        Log::info('Assistant interaction reported.', [
            'interaction_id' => $interaction->id,
            'student_profile_id' => $studentProfile->id,
            'report_status' => $interaction->report_status,
        ]);

        return $interaction;
    }

    /**
     * @throws AssistantException
     */
    private function assertIntentAllowed(string $intent): void
    {
        if (! in_array($intent, self::ALLOWED_INTENTS, true)) {
            throw new AssistantException(
                'The requested assistant intent is outside the permitted scope.',
                422,
                'ASSISTANT_INTENT_NOT_ALLOWED',
                ['intent' => $intent],
            );
        }
    }
}
