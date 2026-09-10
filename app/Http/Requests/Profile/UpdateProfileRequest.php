<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/v1/profile
 *
 * All fields use 'sometimes', so a field the learner did not send is
 * simply left untouched on the model instead of being overwritten with
 * null/empty — this is what SRS 7.1 / PROF-01 mean by "optional fields
 * remain distinguishable from zero or false values". If a field IS sent
 * as null, that is treated as an explicit "clear this field" from the
 * learner.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Nullable here means "explicitly clear this field"; omitting
            // the key entirely leaves it untouched ('sometimes').
            'university_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'student_university_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'specialization' => ['sometimes', 'nullable', 'string', 'max:191'],
            'academic_level' => ['sometimes', 'nullable', 'string', 'max:100'],
            'expected_graduation' => ['sometimes', 'nullable', 'integer', 'min:2024', 'max:2150'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'career_status' => ['sometimes', 'nullable', 'string', 'max:100'],
            'interests' => ['sometimes', 'nullable', 'array'],
            'interests.*' => ['string', 'max:100'],
            'availability' => ['sometimes', 'nullable', 'string', 'max:100'],
            'preferred_work_type' => ['sometimes', 'nullable', 'string', 'max:100'],

            // US-INT-01 §24: a career-role change must target an
            // approved role — draft/retired roles can't drive decisions.
            'primary_career_role_id' => [
                'sometimes',
                'nullable',
                'integer',
                'gt:0',
                'exists:career_roles,id,status,approved',
            ],
            'visibility' => ['sometimes', 'in:public,organization_only,private'],
        ];
    }
}
