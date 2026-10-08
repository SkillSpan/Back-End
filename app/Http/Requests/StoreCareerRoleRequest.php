<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a career role created from the admin specializations page.
 *
 * Skills are optional at validation time — the operator may create a role
 * first and attach skills later — but the panel warns when none are chosen,
 * because a role with no required skills cannot be assessed.
 */
class StoreCareerRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:191'],
            'skill_ids' => ['sometimes', 'array', 'max:20'],
            'skill_ids.*' => ['integer', 'exists:skills,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'A career role title is required.',
            'skill_ids.*.exists' => 'One of the selected skills does not exist.',
        ];
    }
}
