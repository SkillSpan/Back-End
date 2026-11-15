<?php

namespace App\Services\Assistant;

use App\Exceptions\AssistantException;
use App\Models\AssistantInteraction;
use App\Models\StudentProfile;
use App\Services\Intelligence\DecisionSnapshotService;
use App\Support\SafeLog;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssistantService
{
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

    public function ask(StudentProfile $studentProfile, array $input, string $requestId): AssistantAnswer
    {
        $intent = (string) $input['intent'];

        $this->assertIntentAllowed($intent);

        $this->client->assertGovernanceApproval();

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

        $contextGroundsIntent = $this->contextBuilder->groundsIntent($context['snapshot'], $intent);

        $configurationVersion = 'config-v'.$this->snapshotService->resolveConfiguration()->version;

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
                (string) $studentProfile->user_id,
                (string) $input['question'],
                $context['snapshot'],
                $requestId,
                $intent,
            );

            $providerUsed = $response['provider_used'] ?? null;
            $answered = $providerUsed !== null;

            $answerStatus = $response['answer_status'] ?? null;
            $grounded = ($response['grounded'] ?? false) === true;

            $interaction->update([
                'response_status' => $answered
                    ? AssistantInteraction::STATUS_SUCCEEDED
                    : AssistantInteraction::STATUS_FAILED,
                'failure_code' => $answered
                    ? null
                    : 'ASSISTANT_PROVIDERS_UNAVAILABLE',
                'answer_status' => $answerStatus,
                'grounded' => $grounded,
                'prompt_version' => $response['prompt_version'] ?? null,
            ]);

            Log::info('Assistant interaction completed.', [
                'request_id' => $requestId,
                'interaction_id' => $interaction->id,
                'student_profile_id' => $studentProfile->id,
                'intent' => $intent,
                'configuration_version' => $configurationVersion,
                'provider_used' => $providerUsed,
                'prompt_version' => $response['prompt_version'] ?? null,
                'answer_status' => $answerStatus,
                'grounded' => $grounded,
                'context_grounds_intent' => $contextGroundsIntent,
            ]);

            return new AssistantAnswer(
                interaction: $interaction,
                reply: (string) $response['reply'],
                providerUsed: $providerUsed,
                promptVersion: $response['prompt_version'] ?? null,
                answerStatus: $answerStatus,
                grounded: $grounded,
                contextGroundsIntent: $contextGroundsIntent,
            );
        } catch (AssistantException $e) {
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
                'failure_reason' => SafeLog::reason($e),
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
