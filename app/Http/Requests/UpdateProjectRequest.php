<?php

namespace App\Http\Requests;

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
}
