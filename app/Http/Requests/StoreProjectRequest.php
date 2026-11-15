<?php

namespace App\Http\Requests;

use App\Rules\SkillMappedToCareerRole;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $skills = $this->input('required_skills');

        if (! is_array($skills)) {
            return;
        }

        foreach ($skills as $index => $skill) {
            if (! is_array($skill)) {
                continue;
            }

            $level = $skill['minimum_required_level'] ?? $skill['minimum_level'] ?? null;

            if ($level !== null) {
                $skills[$index]['minimum_required_level'] = $level;
                $skills[$index]['minimum_level'] = $level;
            }
        }

        $this->merge(['required_skills' => $skills]);
    }

    protected function careerRoleIdForValidation(): ?int
    {
        $value = $this->input('career_role_id');

        return is_numeric($value) ? (int) $value : null;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['simulation', 'company_sponsored'])],
            'domain' => ['sometimes', 'nullable', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'objectives' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'learning_outcomes' => ['sometimes', 'nullable', 'array', 'max:50'],
            'learning_outcomes.*' => ['string', 'max:500'],
            'difficulty' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:5'],
            'work_mode' => ['sometimes', 'nullable', 'string', 'max:50'],

            'career_role_id' => ['required', 'integer', 'gt:0', 'exists:career_roles,id'],

            'schedule' => ['sometimes', 'nullable', 'string', 'max:255'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'min_team_size' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'start_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'application_deadline' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'confidentiality' => ['sometimes', 'string', Rule::in(['public', 'restricted'])],
            'organization_id' => ['sometimes', 'nullable', 'integer', 'gt:0', 'exists:organizations,id'],

            'owner_id' => ['sometimes', 'nullable', 'integer', 'gt:0', 'exists:users,id'],

            'required_skills' => ['sometimes', 'array', 'max:50'],
            'required_skills.*.skill_id' => [
                'required',
                'integer',
                'gt:0',
                'exists:skills,id',
                'distinct',
                new SkillMappedToCareerRole($this->careerRoleIdForValidation()),
            ],
            'required_skills.*.minimum_required_level' => ['required', 'numeric', 'min:0', 'max:5'],
            'required_skills.*.minimum_level' => ['sometimes', 'numeric', 'min:0', 'max:5'],
            'required_skills.*.is_critical_entry' => ['sometimes', 'boolean'],

            'roles' => ['sometimes', 'array', 'max:50'],
            'roles.*.title' => ['required', 'string', 'max:255', 'distinct'],
            'roles.*.description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'roles.*.is_active' => ['sometimes', 'boolean'],

            'eligibility_constraints' => ['sometimes', 'array', 'max:50'],
            'eligibility_constraints.*.constraint_type' => ['required', Rule::in(['location', 'language', 'schedule', 'work_mode'])],
            'eligibility_constraints.*.value' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'The project type is required.',
            'type.in' => 'The project type must be simulation or company_sponsored.',
            'title.required' => 'The project title is required.',
            'career_role_id.required' => 'The project must be linked to a career role.',
            'career_role_id.exists' => 'The selected career role does not exist.',
            'capacity.min' => 'A project must have room for at least one participant.',
            'required_skills.*.skill_id.distinct' => 'The same skill cannot be required twice.',
            'required_skills.*.skill_id.exists' => 'One of the selected skills does not exist.',
            'required_skills.*.minimum_required_level.required' => 'Every required skill needs a minimum required level.',
            'required_skills.*.minimum_required_level.min' => 'A minimum required level cannot be below 0.',
            'required_skills.*.minimum_required_level.max' => 'A minimum required level cannot be above 5.',
            'roles.*.title.distinct' => 'The same role title cannot be defined twice.',
            'eligibility_constraints.*.constraint_type.in' => 'That eligibility constraint type is not supported.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $requestId = (string) ($this->header('X-Request-ID') ?: Str::uuid());

        throw new HttpResponseException(response()->json([
            'code' => 'VALIDATION_ERROR',
            'message' => 'The request could not be processed.',
            'errors' => $validator->errors(),
            'request_id' => $requestId,
        ], 422, ['X-Request-ID' => $requestId]));
    }
}
