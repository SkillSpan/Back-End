<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
