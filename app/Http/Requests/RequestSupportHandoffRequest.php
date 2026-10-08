<?php

namespace App\Http\Requests;

use App\Models\SupportRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Escalate the assistant conversation to a human.
 *
 * The `transcript` is sent by the client because the platform deliberately
 * stores no assistant conversation (§12.5 data minimisation) — there is no
 * server-side history to read from. It is capped here and again in
 * SupportRequestService, because it is the only learner-authored content that
 * ends up persisted.
 *
 * `source_interaction_id` is optional and its ownership is NOT validated here:
 * SupportRequestController scopes the lookup to the caller's own student
 * profile, so another learner's id is indistinguishable from a non-existent
 * one (BR-11) instead of returning a 403 that confirms the id exists.
 */
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
