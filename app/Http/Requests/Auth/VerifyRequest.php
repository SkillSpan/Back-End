<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // No `exists:users,email`: an unknown address must produce the exact
        // same "invalid or expired code" outcome as a wrong OTP, otherwise
        // this endpoint reveals which emails are registered.
        return [
            'email' => ['required', 'email'],
            'otp' => ['required', 'string', 'size:6'],
        ];
    }
}
