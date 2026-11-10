<?php

namespace App\Http\Requests;

use App\Models\Project;

class UpdateProjectRequest extends StoreProjectRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        foreach ($rules as $field => $fieldRules) {
            if (str_contains($field, '.*')) {
                continue;
            }

            $rules[$field] = array_map(
                fn ($rule) => $rule === 'required' ? 'sometimes' : $rule,
                $fieldRules
            );
        }

        return $rules;
    }

    protected function careerRoleIdForValidation(): ?int
    {
        $submitted = parent::careerRoleIdForValidation();

        if ($submitted !== null) {
            return $submitted;
        }

        $projectId = $this->route('project');

        if (! is_numeric($projectId)) {
            return null;
        }

        $careerRoleId = Project::query()->whereKey((int) $projectId)->value('career_role_id');

        return $careerRoleId !== null ? (int) $careerRoleId : null;
    }
}
