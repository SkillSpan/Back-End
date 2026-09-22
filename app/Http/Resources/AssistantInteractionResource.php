<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * US-REC-01 — assistant interaction presentation.
 *
 * Exposes audit metadata only. The learner's question and the assistant's
 * reply are deliberately absent because they are deliberately not stored
 * (SRS v1.1 §12.5 data minimisation): the response reports the outcome and
 * its provenance, not retained conversation content.
 */
class AssistantInteractionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
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
            'configuration_version' => $this->configuration_version,
            'failure_code' => $this->failure_code,
            'request_id' => $this->request_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
