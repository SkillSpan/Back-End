<?php

namespace App\Http\Requests;

use App\Models\SkillEvidence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
