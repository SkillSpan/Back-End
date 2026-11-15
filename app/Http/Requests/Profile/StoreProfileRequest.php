<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class StoreProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'university_name' => ['required', 'string', 'max:191'],
            'student_university_number' => ['required', 'string', 'max:64'],
            'specialization' => ['required', 'string', 'max:191'],
            'academic_level' => ['required', 'string', 'max:100'],
            'expected_graduation' => ['nullable', 'integer', 'min:2024', 'max:2150'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'career_status' => ['nullable', 'string', 'max:100'],
            'interests' => ['nullable', 'array'],
            'interests.*' => ['string', 'max:100'],
            'availability' => ['nullable', 'string', 'max:100'],
            'weekly_availability_hours' => ['nullable', 'numeric', 'between:0,168'],
            'preferred_work_type' => ['nullable', 'string', 'max:100'],
            'visibility' => ['sometimes', 'in:public,organization_only,private'],
        ];
    }
}
