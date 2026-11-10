<?php

namespace App\Http\Requests;

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
