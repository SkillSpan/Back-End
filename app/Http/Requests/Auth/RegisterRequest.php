<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * نطبّع الإيميل (حروف صغيرة + إزالة مسافات) قبل الفاليديشن،
     * عشان قاعدة unique تكتشف التكرار حتى لو انكتب بحروف مختلفة.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'terms_accepted' => ['required', 'accepted'],
            'privacy_accepted' => ['required', 'accepted'],
            'locale' => ['nullable', 'string', 'max:10', 'in:en,ar'],
            // Academic profile fields (university/specialization) are no
            // longer accepted as free text here — they are normalized
            // Foreign Keys collected via POST /api/v1/profile onboarding.
            'career_status' => ['nullable', 'string', 'max:255'],
            'academic_status' => [
                'nullable',
                'string',
                Rule::in(['enrolled', 'graduated', 'on_leave', 'student', 'graduate', 'looking_for_job', 'employed']),
            ],

            // مسار الأفراد فقط. أي بيانات شركة هون معناها إنه المستخدم
            // بالغلط استخدم هالمسار بدل /api/auth/register/organization.
            'user_type' => ['nullable', 'string', 'in:individual'],
            'organization_name' => ['prohibited'],
            'organization_type' => ['prohibited'],
            'proof_file' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email is already registered.',
            'terms_accepted.accepted' => 'You must accept the Terms and Conditions.',
            'privacy_accepted.accepted' => 'You must accept the Privacy Policy.',
            'user_type.in' => 'This endpoint is for individual accounts only. Please use /api/auth/register/organization to register a company, university, or training partner.',
            'organization_name.prohibited' => 'This endpoint is for individual accounts only. Please use /api/auth/register/organization to register a company, university, or training partner.',
            'organization_type.prohibited' => 'This endpoint is for individual accounts only. Please use /api/auth/register/organization to register a company, university, or training partner.',
            'proof_file.prohibited' => 'This endpoint is for individual accounts only. Please use /api/auth/register/organization to register a company, university, or training partner.',
        ];
    }
}
