<?php

namespace App\Http\Requests\Profile;

use App\Models\CareerRole;
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
            'weekly_availability_hours' => ['sometimes', 'nullable', 'numeric', 'between:0,168'],
            'preferred_work_type' => ['sometimes', 'nullable', 'string', 'max:100'],

            // US-INT-01 §24: a career-role change must target an
            // approved role — draft/retired roles can't drive decisions.
            //
            // MVP: a NEW choice must also be active, so a role the product has
            // switched off cannot be picked again. The learner's CURRENT role
            // is exempt: clients commonly PUT the whole profile back, and a
            // deactivation must never turn an existing profile into an invalid
            // one. Only an actual change is validated against `is_active`.
            'primary_career_role_id' => [
                'sometimes',
                'nullable',
                'integer',
                'gt:0',
                'exists:career_roles,id,status,approved',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_numeric($value)) {
                        return; // already reported by the rules above
                    }

                    $currentRoleId = $this->user()?->studentProfile?->primary_career_role_id;

                    if ($currentRoleId !== null && (int) $currentRoleId === (int) $value) {
                        return; // unchanged — keep the historical choice valid
                    }

                    $isSelectable = CareerRole::query()
                        ->selectable()
                        ->whereKey((int) $value)
                        ->exists();

                    if (! $isSelectable) {
                        $fail('The selected career role is not available.');
                    }
                },
            ],
            'visibility' => ['sometimes', 'in:public,organization_only,private'],
        ];
    }
}
