<?php

namespace App\Http\Requests;

use App\Models\AssistantInteraction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * US-REC-01 — report an assistant response.
 *
 * SRS v1.1 §12.6 (incident and dispute flow) / REC-08: a learner may report
 * a response as unsafe, irrelevant, unfair or incorrect, and the recorded
 * reason travels with the classification.
 *
 * Ownership is NOT validated here — it is enforced by the scoped lookup in
 * AssistantService::report(), so an unauthorised id cannot be distinguished
 * from a non-existent one (BR-11).
 */
class ReportAssistantInteractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'report_status' => [
                'required',
                'string',
                Rule::in([
                    AssistantInteraction::REPORT_UNSAFE,
                    AssistantInteraction::REPORT_IRRELEVANT,
                    AssistantInteraction::REPORT_UNFAIR,
                    AssistantInteraction::REPORT_INCORRECT,
                ]),
            ],
            'report_reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'report_status.required' => 'A report classification is required.',
            'report_status.in' => 'The report classification is not recognised.',
        ];
    }
}
