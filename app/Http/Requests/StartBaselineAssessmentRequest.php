<?php

namespace App\Http\Requests;

use App\Models\CareerRole;
use Illuminate\Foundation\Http\FormRequest;

class StartBaselineAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'career_role_id' => [
                'required',
                'integer',
                'exists:career_roles,id',
                function ($attribute, $value, $fail) {
                    $role = CareerRole::find($value);

                    if (! $role) {
                        return; // already reported by the exists rule
                    }

                    if ($role->status !== 'approved') {
                        $fail('The selected career role is not approved.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'career_role_id.required' => 'A career role ID is required to start a baseline assessment.',
            'career_role_id.integer' => 'The career role ID must be an integer.',
            'career_role_id.exists' => 'The selected career role does not exist.',
        ];
    }
}
