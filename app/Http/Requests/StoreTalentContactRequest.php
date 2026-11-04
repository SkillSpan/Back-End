<?php

namespace App\Http\Requests;

/**
 * Ask a candidate to get in touch.
 *
 * The message is required: the learner receives a request with no context
 * otherwise, and has no way to judge whether to accept. 2000 characters is
 * enough for a role description and a link.
 */
class StoreTalentContactRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'message.required' => 'Tell the candidate why you would like to get in touch.',
        ];
    }
}
