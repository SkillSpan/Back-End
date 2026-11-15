<?php

namespace App\Http\Requests;

class StoreTalentListCandidateRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'gt:0'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'student_id.required' => 'The candidate is required.',
        ];
    }
}
