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
            'education' => ['sometimes', 'nullable', 'string', 'max:255'],
            'specialization' => ['sometimes', 'nullable', 'string', 'max:255'],
            'career_status' => ['sometimes', 'nullable', 'string', 'max:100'],
            'interests' => ['sometimes', 'nullable', 'array'],
            'interests.*' => ['string', 'max:100'],
            'availability' => ['sometimes', 'nullable', 'string', 'max:100'],
            'preferred_work_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'visibility' => ['sometimes', 'in:public,organization_only,private'],
        ];
    }
}
