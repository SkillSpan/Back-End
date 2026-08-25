<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/profile
 *
 * Creates the learner's student profile (SRS PROF-01). In practice a
 * StudentProfile row already exists for every learner right after
 * registration (see AuthService::createStudentProfile()), so this
 * endpoint is meant for completing that profile during onboarding
 * (UC-02) rather than a bare "first write". The controller still
 * rejects the call with 409 if a profile row already exists, so this
 * request only needs to validate the shape of the data itself.
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
            // NOTE: education/specialization are plain strings for now.
            // Per the Sprint 2 task list, converting these to normalized
            // university/specialization foreign keys is pending a decision
            // from the Database owner — keep these rules (and the
            // student_profiles migration) in sync once that lands.
            'education' => ['required', 'string', 'max:255'],
            'specialization' => ['required', 'string', 'max:255'],
            'career_status' => ['nullable', 'string', 'max:100'],
            'interests' => ['nullable', 'array'],
            'interests.*' => ['string', 'max:100'],
            'availability' => ['nullable', 'string', 'max:100'],
            'preferred_work_type' => ['nullable', 'string', 'max:100'],
            'visibility' => ['nullable', 'in:public,organization_only,private'],
        ];
    }
}
