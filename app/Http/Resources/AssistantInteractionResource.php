<?php

namespace App\Http\Resources;

use App\Services\Assistant\AssistantAnswer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * US-REC-01 — assistant interaction presentation.
 *
 * Exposes audit metadata, plus the assistant's reply on the /ask response.
 *
 * The reply is **relayed, never stored**, and that split is the whole design:
 * §12.5 data minimisation means the conversation is not persisted, but dropping
 * the reply entirely would leave the UI with nothing to show. So it travels on
 * {@see AssistantAnswer}, which is deliberately not an Eloquent model — an
 * unpersisted attribute on a model *looks* storable, and one careless `save()`
 * later it is in the database.
 *
 * `answer` is null on the /report response, which has no reply to relay.
 */
class AssistantInteractionResource extends JsonResource
{
    /**
     * Set by AssistantController::ask() only.
     *
     * Declared as a real property rather than left dynamic so the read in
     * toArray() is not routed through JsonResource::__get, which forwards to the
     * underlying model and would find nothing there.
     */
    public ?AssistantAnswer $answer = null;

    public function toArray(Request $request): array
    {
        $payload = [
            'id' => $this->id,
            'intent' => $this->intent,
            'context_reference' => $this->context_reference,
            'related_recommendation_id' => $this->related_recommendation_id,
            'related_project_id' => $this->related_project_id,
            'response_status' => $this->response_status,
            'report_status' => $this->report_status,
            'report_reason' => $this->report_reason,
            'reported_at' => $this->reported_at?->toIso8601String(),
            'algorithm_version' => $this->algorithm_version,
            'prompt_version' => $this->prompt_version,
            'configuration_version' => $this->configuration_version,
            'failure_code' => $this->failure_code,
            'request_id' => $this->request_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        /*
         * Only on /ask. Note that `reply` being present here while
         * `response_status` is `failed` is a coherent combination, not a bug:
         * the service answered with its fallback text, so there IS a reply, but
         * no model produced it — see AssistantService::ask(). Monitoring should
         * branch on response_status; the UI should render the reply.
         */
        if ($this->answer !== null) {
            $payload['reply'] = $this->answer->reply;
            $payload['provider_used'] = $this->answer->providerUsed;
        }

        return $payload;
    }
}
