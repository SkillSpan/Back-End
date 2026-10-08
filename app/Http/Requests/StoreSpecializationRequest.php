<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a specialization created from the admin panel.
 *
 * The name is unique because it is the key every seeder and reference
 * lookup resolves a specialization by.
 */
class StoreSpecializationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route carries the `admin` middleware.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191', 'unique:specializations,name'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'A specialization name is required.',
            'name.unique' => 'A specialization with this name already exists.',
        ];
    }
}
