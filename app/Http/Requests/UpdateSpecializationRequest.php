<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Validates a specialization edited from the admin panel.
 *
 * Identical to create, except the unique-name rule ignores the row being
 * edited so saving without changing the name is not a false conflict.
 */
class UpdateSpecializationRequest extends StoreSpecializationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:191',
                Rule::unique('specializations', 'name')->ignore($this->route('specialization')),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
