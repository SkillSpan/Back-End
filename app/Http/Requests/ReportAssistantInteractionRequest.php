<?php

namespace App\Http\Requests;

use App\Models\AssistantInteraction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
