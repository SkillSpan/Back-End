<?php

namespace App\Http\Requests;

use App\Models\SupportRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestSupportHandoffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => [
                'sometimes',
                'string',
                Rule::in([
                    SupportRequest::REASON_INSUFFICIENT_CONTEXT,
                    SupportRequest::REASON_LEARNER_REQUESTED,
                ]),
            ],

            'subject' => ['sometimes', 'nullable', 'string', 'max:180'],

            'transcript' => ['sometimes', 'array', 'max:40'],
            'transcript.*.role' => ['sometimes', 'string', Rule::in(['learner', 'assistant'])],
            'transcript.*.body' => ['required_with:transcript', 'string', 'max:4000'],

            'source_interaction_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.in' => 'The handoff reason is not recognised.',
            'transcript.max' => 'The conversation is too long to transfer. Please describe your question instead.',
            'transcript.*.body.required_with' => 'Each transferred message needs a body.',
        ];
    }
}
