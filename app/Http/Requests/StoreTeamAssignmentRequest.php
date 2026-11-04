<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * Staff a project from its accepted applications.
 *
 * Shape only. Whether the actor may manage the project, whether the project is
 * in an assignable status, whether each application is actually ACCEPTED, and
 * whether the team would exceed capacity are all business rules answered by
 * `ProjectTeamService` — the same split the rest of the project API uses, so
 * the refusal carries a precise code instead of a generic validation error.
 *
 * `distinct` on `members.*.application_id` stops the same application being
 * staffed twice in one request, which is the one case that can be caught here
 * cheaply and would otherwise need a per-row lookup.
 */
class StoreTeamAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'members' => ['required', 'array', 'min:1', 'max:100'],
            'members.*.application_id' => ['required', 'integer', 'gt:0', 'distinct'],
            'members.*.project_role' => ['sometimes', 'nullable', 'string', 'max:255'],
            // The role may be named or identified. A screen that renders the
            // project's roles has their ids, not their titles, so demanding a
            // title would force every client to look the name up first.
            'members.*.project_role_id' => ['sometimes', 'nullable', 'integer', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'members.required' => 'At least one team member is required.',
            'members.min' => 'At least one team member is required.',
            'members.*.application_id.distinct' => 'The same application cannot be assigned twice.',
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
