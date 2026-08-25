<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ResendOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // No `exists:users,email` here: an unknown address must reach the
        // controller and receive the SAME neutral response as a known one,
        // otherwise this endpoint leaks which emails are registered.
        return [
            'email' => ['required', 'email'],
        ];
    }
}
