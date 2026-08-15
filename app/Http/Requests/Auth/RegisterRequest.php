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
            'education' => ['nullable', 'string', 'max:500'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'career_status' => ['nullable', 'string', 'max:255'],
            'academic_status' => [
                'nullable',
                'string',
                Rule::in(['enrolled', 'graduated', 'on_leave', 'student', 'graduate', 'looking_for_job', 'employed']),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email is already registered.',
            'terms_accepted.accepted' => 'You must accept the Terms and Conditions.',
            'privacy_accepted.accepted' => 'You must accept the Privacy Policy.',
        ];
    }
}
