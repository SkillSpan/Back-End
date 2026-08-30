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
            // Shape-only checks on purpose: whether consent is REQUIRED is
            // decided after the ID token resolves the account — existing
            // users must be able to log in without re-consenting, and the
            // hard gate (both flags true) lives only on the account-creation
            // path in AuthService::loginWithGoogle().
            'terms_accepted' => ['nullable', 'boolean'],
            'privacy_accepted' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'credential.required' => 'Google authentication credential is required.',
        ];
    }
}
