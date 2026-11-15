<?php

namespace App\Http\Requests;

class StoreTalentNoteRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'The note cannot be empty.',
        ];
    }
}
