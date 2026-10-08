<?php

namespace App\Rules;

use App\Models\CareerRoleSkill;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * US-MATCH-DATA-03 — a project's required skill must belong to the selected
 * career role's approved skill mapping.
 *
 * It checks the EXISTING `career_role_skills` mapping (the same rows
 * CareerRole::skills() / roleSkills() expose) rather than merely asking
 * whether the skill exists in the global `skills` table. A skill that exists
 * globally but is not mapped to the selected career role is therefore
 * rejected with a 422 validation error.
 *
 * When no career role is available to check against (a legacy project with no
 * career role being partially updated), the rule passes: the "a project must
 * have a career role" requirement is enforced separately by the
 * `career_role_id` rule, and inventing a failure here would report the wrong
 * field.
 */
class SkillMappedToCareerRole implements ValidationRule
{
    public function __construct(private readonly ?int $careerRoleId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->careerRoleId === null) {
            return;
        }

        if (! is_numeric($value)) {
            return;
        }

        $mapped = CareerRoleSkill::query()
            ->where('career_role_id', $this->careerRoleId)
            ->where('skill_id', (int) $value)
            ->exists();

        if (! $mapped) {
            $fail('The selected skill is not part of the chosen career role.');
        }
    }
}
