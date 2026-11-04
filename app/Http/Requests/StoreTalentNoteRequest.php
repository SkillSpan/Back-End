<?php

namespace App\Http\Requests;

/**
 * Write an internal note about a candidate.
 *
 * `body` is required: an empty note is a row that says nothing and still
 * appears in the list. 2000 characters is a note, not a document.
 */
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
