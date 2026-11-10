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
