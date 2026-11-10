<?php

namespace App\Http\Requests\Profile;

use App\Models\CareerRole;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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

            'primary_career_role_id' => [
                'sometimes',
                'nullable',
                'integer',
                'gt:0',
                'exists:career_roles,id,status,approved',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_numeric($value)) {
                        return;
                    }

                    $currentRoleId = $this->user()?->studentProfile?->primary_career_role_id;

                    if ($currentRoleId !== null && (int) $currentRoleId === (int) $value) {
                        return;
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
