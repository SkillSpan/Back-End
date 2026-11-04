<?php

namespace App\Http\Requests;

/**
 * Add a candidate to a shortlist.
 *
 * `student_id` is the user id of the learner, which is what `GET /talent`
 * returns as `id` — not the `student_profiles` row id. Whether that learner is
 * visible to this company is decided by the service, against the same rule the
 * talent list uses.
 */
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
