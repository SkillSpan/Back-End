<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit the panel user's own details.
 *
 * Every rule is `sometimes`, so the payload may carry any subset — an absent key
 * means "leave it alone" and an explicit `null` means "clear it". That
 * distinction is what lets the UI send one field without wiping the rest, and
 * it is enforced again in AdminProfileService.
 *
 * `name` lives on `users`; the rest live on `admin_profiles`. The split is an
 * implementation detail the client never sees — it sends one flat object.
 *
 * `age` is deliberately a narrow band. The column is an unsigned tinyint and
 * would accept 0 or 255, both of which would render as nonsense on the profile
 * card; the range is the only thing that makes the value mean "a person's age".
 */
class UpdateAdminProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],

            'display_title' => ['sometimes', 'nullable', 'string', 'max:60'],

            'bio' => ['sometimes', 'nullable', 'string', 'max:600'],

            'age' => ['sometimes', 'nullable', 'integer', 'min:16', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'A name is required.',
            'name.min' => 'The name must be at least 2 characters.',
            'display_title.max' => 'The job title may not be longer than 60 characters.',
            'bio.max' => 'The bio may not be longer than 600 characters.',
            'age.min' => 'The age must be at least 16.',
            'age.max' => 'The age may not be greater than 100.',
        ];
    }
}
