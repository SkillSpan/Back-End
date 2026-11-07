<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the optional filters on GET /api/v1/career-roles.
 *
 * Every parameter is optional: when it is absent (or sent empty, which Laravel
 * normalises to null) the endpoint keeps its original "list every approved
 * career role" behaviour. When `specialization_id` is present it must be a
 * positive integer referencing an existing specialization, otherwise the
 * request fails with the standard Laravel validation envelope — the same
 * convention StartBaselineAssessmentRequest uses for career_role_id.
 *
 * `search` was added because the learner Career Roles page was filtering the
 * first page client-side only: a role on page 2 could never be found, no
 * matter what was typed. The list is paginated server-side (15 by default), so
 * the filter has to reach the query or it silently searches 15 rows out of N.
 */
class ListCareerRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'specialization_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'exists:specializations,id',
            ],
            // Free text. A cap rather than a strict format: this goes into a
            // LIKE pattern, and an unbounded string is a cheap way to make the
            // database scan on every keystroke.
            'search' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'specialization_id.integer' => 'The specialization ID must be a positive integer.',
            'specialization_id.min' => 'The specialization ID must be a positive integer.',
            'specialization_id.exists' => 'The selected specialization does not exist.',
            'search.max' => 'The search term may not be longer than 120 characters.',
        ];
    }
}
