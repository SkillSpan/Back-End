<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the optional `specialization_id` filter on
 * GET /api/v1/career-roles.
 *
 * The parameter is entirely optional: when it is absent (or sent empty,
 * which Laravel normalises to null) the endpoint keeps its original
 * "list every approved career role" behaviour. When it is present it must
 * be a positive integer referencing an existing specialization, otherwise
 * the request fails with the standard Laravel validation envelope — the
 * same convention StartBaselineAssessmentRequest uses for career_role_id.
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
        ];
    }

    public function messages(): array
    {
        return [
            'specialization_id.integer' => 'The specialization ID must be a positive integer.',
            'specialization_id.min' => 'The specialization ID must be a positive integer.',
            'specialization_id.exists' => 'The selected specialization does not exist.',
        ];
    }
}
