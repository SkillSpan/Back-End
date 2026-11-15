<?php

namespace App\Http\Requests;

use App\Models\CareerRole;
use App\Models\Specialization;
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
                        return;
                    }

                    if ($role->status !== 'approved') {
                        $fail('The selected career role is not approved.');

                        return;
                    }

                    if (! $role->is_active) {
                        $fail('The selected career role is not available.');
                    }
                },
            ],

            'specialization_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'exists:specializations,id',
                function ($attribute, $value, $fail) {
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
        ];
    }

    public function messages(): array
    {
        return [
            'career_role_id.required' => 'A career role ID is required to start a baseline assessment.',
            'career_role_id.integer' => 'The career role ID must be an integer.',
            'career_role_id.exists' => 'The selected career role does not exist.',
            'specialization_id.integer' => 'The specialization ID must be a positive integer.',
            'specialization_id.min' => 'The specialization ID must be a positive integer.',
            'specialization_id.exists' => 'The selected specialization does not exist.',
        ];
    }
}
