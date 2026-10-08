<?php

namespace App\Http\Requests;

use App\Rules\SkillMappedToCareerRole;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Project Management Workflow — project creation.
 *
 * Shape and ranges only. Whether the actor may create at all, which
 * organization the project belongs to, and what status it starts in are
 * authorization / business questions answered by ProjectLifecycleService —
 * the same split StoreApplicationRequest uses.
 *
 * `organization_id` and `owner_id` are accepted but NOT authoritative:
 *
 *   - company_sponsored — the project is COMPANY OWNED. A company
 *     representative's project always belongs to their own organization and is
 *     owned by them, so a supplied value is ignored; a platform administrator
 *     creating on behalf of a company must name both, and both are validated
 *     against the organization's active administrators.
 *   - simulation — SkillSpan-internal. It is never auto-linked to the creator's
 *     organization, so `organization_id` stays null unless the caller names an
 *     organization they actually administer.
 *
 * Ownership is therefore never taken from the payload on trust — see
 * ProjectLifecycleService::resolveOwnership().
 *
 * Date ORDERING (end >= start, deadline <= start) is deliberately not expressed
 * with `after_or_equal` here: on an update a field may be omitted and taken
 * from the stored row, which a request-level comparison against a missing
 * sibling cannot see. The service validates ordering against the final state.
 */
class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * US-MATCH-DATA-03 — normalise the minimum required level field name.
     *
     * The canonical field is `minimum_required_level`. The legacy
     * `minimum_level` name is accepted as an alias so existing clients keep
     * working. Both are written to the same single value so the service can
     * keep reading `minimum_level` (the actual `project_required_skills`
     * column) with no duplicate source of truth.
     */
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

    /**
     * The career role a submitted skill must belong to.
     *
     * On create this is simply the submitted `career_role_id`. UpdateProjectRequest
     * overrides this to fall back to the project's stored career role when the
     * caller does not resend it, so a partial update is validated against the
     * role the project actually has.
     */
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

            // US-MATCH-DATA-03 — a project MUST target a real career role. This
            // is the source of truth for which skills may be required, and it
            // replaces the old free-text `role` column as the writable input.
            // The legacy `role` value is now derived from the career role title
            // by ProjectLifecycleService.
            'career_role_id' => ['required', 'integer', 'gt:0', 'exists:career_roles,id'],

            'schedule' => ['sometimes', 'nullable', 'string', 'max:255'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'min_team_size' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'application_deadline' => ['sometimes', 'nullable', 'date'],
            'confidentiality' => ['sometimes', 'string', Rule::in(['public', 'restricted'])],
            'organization_id' => ['sometimes', 'nullable', 'integer', 'gt:0', 'exists:organizations,id'],

            // Only honoured when a PLATFORM ADMINISTRATOR creates a
            // company_sponsored project on behalf of a company — the company
            // representative who will own it. A company representative's own
            // project is always owned by them, and a simulation is always owned
            // by its creator, so the value is ignored in those cases rather than
            // silently reassigning ownership.
            'owner_id' => ['sometimes', 'nullable', 'integer', 'gt:0', 'exists:users,id'],

            // `distinct` mirrors the unique indexes on the child tables, so a
            // duplicated skill or role title is reported as a validation error
            // instead of surfacing as a database constraint violation.
            'required_skills' => ['sometimes', 'array', 'max:50'],
            // Every required skill must exist AND be part of the selected
            // career role's approved mapping. The custom rule checks the
            // existing `career_role_skills` rows, not merely the global
            // `skills` table.
            'required_skills.*.skill_id' => [
                'required',
                'integer',
                'gt:0',
                'exists:skills,id',
                'distinct',
                new SkillMappedToCareerRole($this->careerRoleIdForValidation()),
            ],
            // The minimum required level is REQUIRED for every attached skill.
            // Numeric, 0–5, decimals supported (the column is decimal(3,2)).
            'required_skills.*.minimum_required_level' => ['required', 'numeric', 'min:0', 'max:5'],
            'required_skills.*.minimum_level' => ['sometimes', 'numeric', 'min:0', 'max:5'],
            'required_skills.*.is_critical_entry' => ['sometimes', 'boolean'],

            'roles' => ['sometimes', 'array', 'max:50'],
            'roles.*.title' => ['required', 'string', 'max:255', 'distinct'],
            'roles.*.description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'roles.*.is_active' => ['sometimes', 'boolean'],

            // The constraint vocabulary is the one ProjectEligibilityService
            // already understands — no new types are invented here.
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

    /**
     * Laravel validates before the controller body runs, so the framework
     * default would emit a 422 with no `code` — unlike every other error this
     * API returns. Emit the shared VALIDATION_ERROR envelope instead.
     */
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
