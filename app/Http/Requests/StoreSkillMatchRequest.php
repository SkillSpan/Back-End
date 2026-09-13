<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSkillMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_profile_id' => ['required', 'integer', 'min:1'],
            'career_role_id' => ['required', 'integer', 'min:1'],
            'career_role_version' => ['required', 'integer', 'min:1'],
            'user_id' => ['required', 'integer', 'min:1'],
            'target_role' => ['required', 'string', 'min:2', 'max:100'],

            'skills' => ['required', 'array', 'min:1'],
            'skills.*.skill_id' => ['required', 'integer', 'min:1'],
            'skills.*.skill_name' => ['required', 'string', 'min:2', 'max:100'],
            'skills.*.current_level' => ['required', 'numeric', 'between:0,5'],
            'skills.*.required_level' => ['required', 'numeric', 'between:0,5'],
            'skills.*.importance_weight' => ['required', 'numeric', 'gt:0', 'lte:1'],
            'skills.*.is_critical' => ['required', 'boolean'],
        ];
    }
}
