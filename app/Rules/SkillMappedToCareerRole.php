<?php

namespace App\Rules;

use App\Models\CareerRoleSkill;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

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
