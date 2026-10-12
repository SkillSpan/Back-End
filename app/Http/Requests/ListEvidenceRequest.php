<?php

namespace App\Http\Requests;

use App\Models\SkillEvidence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the optional `status` filter on GET /api/v1/evidence.
 *
 * The parameter is entirely optional: when it is absent (or sent empty, which
 * Laravel normalises to null) the endpoint returns the learner's evidence in
 * every verification state. When it is present it must be one of the states
 * the `skill_evidences.verification_status` enum accepts — otherwise the
 * request fails with the standard Laravel validation envelope.
 *
 * The allowed values are read from SkillEvidence::STATUSES rather than being
 * repeated here, so adding a state in one place can never leave this filter
 * (or its error message) out of date.
 */
class ListEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(SkillEvidence::STATUSES),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'The selected status is invalid. Allowed values: '
                .implode(', ', SkillEvidence::STATUSES).'.',
        ];
    }
}
