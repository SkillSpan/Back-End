<?php

namespace App\Http\Requests;

use App\Models\CareerRole;
use App\Models\Specialization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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

            // Optional: when supplied, the career role must actually belong
            // to this specialization. Omitting it preserves the original
            // career_role_id-only contract for existing consumers.
            'specialization_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'exists:specializations,id',
            ],
        ];
    }

    /**
     * Cross-field rule: a career role may only be assessed under a
     * specialization it is actually linked to. Validated after the
     * per-field rules so a bad id on either side is reported first, and the
     * mismatch is never silently accepted.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $specializationId = $this->input('specialization_id');

            if ($specializationId === null || $specializationId === '') {
                return;
            }

            if ($validator->errors()->has('specialization_id')
                || $validator->errors()->has('career_role_id')) {
                return;
            }

            $careerRole = CareerRole::find($this->input('career_role_id'));

            if (! $careerRole) {
                return;
            }

            // A normal specialization accepts only the roles linked to it;
            // the "Self-Learning / Free Track" accepts any existing role.
            $specialization = Specialization::find($specializationId);

            $allowed = $specialization !== null
                && $specialization->allowsCareerRole((int) $careerRole->id);

            if (! $allowed) {
                $validator->errors()->add(
                    'career_role_id',
                    'The selected career role does not belong to the selected specialization.',
                );
            }
        });
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
