<?php

namespace App\Http\Requests;

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
 * `organization_id` is accepted but NOT authoritative: the service derives the
 * organization from the actor's own admin membership and ignores a supplied
 * value for anyone but a platform administrator, so it can never be used to
 * publish into another organization.
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
            'role' => ['sometimes', 'nullable', 'string', 'max:255'],
            'schedule' => ['sometimes', 'nullable', 'string', 'max:255'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'min_team_size' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'application_deadline' => ['sometimes', 'nullable', 'date'],
            'confidentiality' => ['sometimes', 'string', Rule::in(['public', 'restricted'])],
            'organization_id' => ['sometimes', 'nullable', 'integer', 'gt:0', 'exists:organizations,id'],

            // `distinct` mirrors the unique indexes on the child tables, so a
            // duplicated skill or role title is reported as a validation error
            // instead of surfacing as a database constraint violation.
            'required_skills' => ['sometimes', 'array', 'max:50'],
            'required_skills.*.skill_id' => ['required', 'integer', 'gt:0', 'exists:skills,id', 'distinct'],
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
            'capacity.min' => 'A project must have room for at least one participant.',
            'required_skills.*.skill_id.distinct' => 'The same skill cannot be required twice.',
            'required_skills.*.skill_id.exists' => 'One of the selected skills does not exist.',
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
