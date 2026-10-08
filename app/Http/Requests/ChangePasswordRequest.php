<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Change the panel user's own password.
 *
 * `current_password` is not optional. The caller already holds an authenticated
 * session, so in principle they could be trusted — but a session is also what an
 * unattended browser holds. Requiring the existing password turns "someone
 * walked up to an unlocked laptop" from a permanent account takeover into
 * nothing at all.
 *
 * The rule set mirrors RegisterRequest / ResetPasswordRequest exactly
 * (`Password::min(8)` with `confirmed`) so a password that is accepted when the
 * account is created cannot be rejected when it is changed.
 *
 * `current_password` is validated by the service, not by a `current_password`
 * rule, so the refusal carries the project's `{code, message, request_id}`
 * envelope instead of Laravel's default validation shape.
 */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8), 'different:current_password'],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Enter your current password.',
            'password.required' => 'Choose a new password.',
            'password.confirmed' => 'The two new passwords do not match.',
            'password.min' => 'The new password must be at least 8 characters.',
            'password.different' => 'The new password must be different from the current one.',
        ];
    }
}
