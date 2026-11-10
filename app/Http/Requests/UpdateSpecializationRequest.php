<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateSpecializationRequest extends StoreSpecializationRequest
{
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
