<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
 *
 * Per the learner-profile task list, university and specialization are
 * normalized Foreign Keys (universities / specializations tables) —
 * NOT free text — and academic_level / expected_graduation / bio were
 * added alongside them.
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
            'university_id' => ['required', 'integer', Rule::exists('universities', 'id')->where('is_active', true)],
            'specialization_id' => ['required', 'integer', Rule::exists('specializations', 'id')->where('is_active', true)],
            'academic_level' => ['required', 'string', 'max:100'],
            'expected_graduation' => ['nullable', 'date', 'after_or_equal:today'],
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
