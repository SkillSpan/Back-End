<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCareerRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:191'],
            'skill_ids' => ['sometimes', 'array', 'max:20'],
            'skill_ids.*' => ['integer', 'exists:skills,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'A career role title is required.',
            'skill_ids.*.exists' => 'One of the selected skills does not exist.',
        ];
    }
}
