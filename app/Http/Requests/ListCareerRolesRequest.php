<?php

namespace App\Http\Requests;

use App\Models\Specialization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the optional filters on GET /api/v1/career-roles.
 *
 * Every parameter is optional: when it is absent (or sent empty, which Laravel
 * normalises to null) the endpoint keeps its original "list every selectable
 * career role" behaviour. When `specialization_id` is present it must be a
 * positive integer referencing an existing AND ACTIVE specialization,
 * otherwise the request fails with the standard Laravel validation envelope —
 * the same convention StartBaselineAssessmentRequest uses for career_role_id.
 *
 * WHY AN INACTIVE SPECIALIZATION IS REJECTED
 * ------------------------------------------
 * Since the MVP a specialization only decides the ORDER of the career-role
 * list, so an inactive one would silently produce the neutral order and let a
 * client keep using a track the product no longer offers. Rejecting it keeps
 * "you cannot choose an inactive specialization" true on the one endpoint that
 * accepts a specialization as input. The error stays a 422 validation error, so
 * the response shape is unchanged.
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
                function (string $attribute, mixed $value, \Closure $fail): void {
                    // The `exists` rule above already reported a bad id.
                    if (! is_numeric($value)) {
                        return;
                    }

                    $isActive = Specialization::query()
                        ->whereKey((int) $value)
                        ->where('is_active', true)
                        ->exists();

                    if (! $isActive) {
                        $fail('The selected specialization is not available.');
                    }
                },
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
