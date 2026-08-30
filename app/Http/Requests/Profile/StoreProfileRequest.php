<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/profile
 *
 * Saves the learner's student profile (SRS PROF-01). A StudentProfile row
 * is created at registration (AuthService::createStudentProfile()), so
 * POST acts as an upsert: it fills the existing row (completing it during
 * onboarding, UC-02) or creates one if missing. It never rejects an
 * existing profile, so the frontend can always POST the full data.
 *
 * Per the learner-profile task: university and specialization are
 * stored as free text (the learner types the university name and picks
 * the specialization from an autocomplete list), not as Foreign Keys.
 */
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
            'preferred_work_type' => ['nullable', 'string', 'max:100'],
            'visibility' => ['nullable', 'in:public,organization_only,private'],
        ];
    }
}
