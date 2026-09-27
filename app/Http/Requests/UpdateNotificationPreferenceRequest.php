<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a notification-preference upsert. Channels are constrained to
 * the ones the system actually dispatches on, so a typo cannot silently
 * create a preference row that will never be consulted.
 */
class UpdateNotificationPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'max:100'],
            'channel' => ['required', 'string', 'in:in_app,email'],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
