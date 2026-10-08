<?php

namespace App\Http\Requests;

use App\Models\Project;

/**
 * Project Management Workflow — project update.
 *
 * Same shape as creation, but every TOP-LEVEL field becomes optional so a
 * partial update only touches what it sends.
 *
 * Nested element rules keep their `required`: a wildcard rule only applies to
 * an element that was actually supplied, so `required_skills.*.skill_id`
 * staying required is what stops a half-specified skill entry
 * (`{minimum_level: 3}` with no skill) from reaching the service.
 *
 * Which statuses may be updated at all, and who may update them, is decided by
 * ProjectLifecycleService — not here.
 */
class UpdateProjectRequest extends StoreProjectRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        foreach ($rules as $field => $fieldRules) {
            // Wildcard rules are per-element and must keep `required`.
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

    /**
     * US-MATCH-DATA-03 — resolve the career role a submitted skill is checked
     * against on update.
     *
     * A partial update may change `required_skills` without resending
     * `career_role_id`, so the submitted skills are validated against the
     * project's STORED career role. When the caller does resend it, that new
     * value wins. This keeps update validation identical to create without
     * forcing every partial update to restate the career role.
     */
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
