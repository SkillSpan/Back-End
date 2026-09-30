<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * Project Management Workflow — a reviewer's decision on a submitted project
 * (approve / request changes / reject).
 *
 * `reason` is optional at the shape level and is recorded in the audit row
 * when present — the projects table has no decision-reason column and the
 * Pilot did not ask for one, so no column is invented. Whether a reason is
 * MANDATORY for a given decision (it is for `changes_requested`) is a business
 * rule enforced by ProjectLifecycleService, which returns a precise
 * PROJECT_REASON_REQUIRED code rather than a generic validation error.
 */
class ProjectReviewDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.max' => 'The reason must not exceed 1000 characters.',
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
