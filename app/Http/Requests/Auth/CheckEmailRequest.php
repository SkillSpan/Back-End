<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/auth/check-email
 *
 * Validates the single input of the email-availability endpoint.
 *
 * Deliberately does NOT carry the `unique:users,email` rule that
 * RegisterRequest uses: a duplicate address is not an *invalid* input here,
 * it is the answer. The endpoint reports it as `data.exists = true` instead of
 * turning it into a 422.
 *
 * Also deliberately does NOT carry `indisposable`. The endpoint's single job is
 * "is this address already registered", and a disposable domain is not a
 * registration fact — it is rejected later, by RegisterRequest. See the note in
 * the handover doc for the frontend consequence.
 */
class CheckEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise exactly the way registration does (lowercase + trim), so the
     * endpoint's verdict matches what POST /auth/register will decide. Without
     * this, "Ahmed@Example.com " would report as available while registration
     * reports it as a duplicate.
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
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
