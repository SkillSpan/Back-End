<?php

namespace App\Http\Resources;

use App\Services\Assistant\AssistantAnswer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssistantInteractionResource extends JsonResource
{
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
            'answer_status' => $this->answer_status,
            'grounded' => $this->grounded,
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

        if ($this->answer !== null) {
            $payload['reply'] = $this->answer->reply;
            $payload['provider_used'] = $this->answer->providerUsed;
            $payload['handoff_available'] = $this->answer->needsHumanHandoff();
        }

        return $payload;
    }
}
