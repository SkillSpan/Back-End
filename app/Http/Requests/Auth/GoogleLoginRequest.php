<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class GoogleLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'credential' => ['required', 'string'],
            'terms_accepted' => ['nullable', 'accepted'],
            'privacy_accepted' => ['nullable', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'credential.required' => 'Google authentication credential is required.',
            'terms_accepted.accepted' => 'You must accept the Terms and Conditions.',
            'privacy_accepted.accepted' => 'You must accept the Privacy Policy.',
        ];
    }
}
