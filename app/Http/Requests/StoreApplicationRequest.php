<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * US-MATCH-02 — application submission validation.
 *
 * Shape only. Everything that depends on the project (does the role belong to
 * it, is the learner eligible, is there a seat left) is an authorization /
 * business question answered by ApplicationService, not here — the same split
 * AskAssistantRequest uses for `recommendation_id`.
 *
 * `project_role_id` and `recommendation_id` are NOT validated with `exists:` on
 * purpose: the service scopes them through the project and the learner, and a
 * bare `exists:` rule would report a cross-project or foreign id as a generic
 * validation error instead of the precise domain code the workflow returns.
 */
class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_role_id' => ['sometimes', 'nullable', 'integer', 'gt:0'],
            'application_data' => ['sometimes', 'nullable', 'array'],
            'recommendation_id' => ['sometimes', 'nullable', 'integer', 'gt:0'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:191'],
        ];
    }

    public function messages(): array
    {
        return [
            'project_role_id.integer' => 'The selected role is invalid.',
            'application_data.array' => 'Application answers must be structured data, not a string.',
            'recommendation_id.integer' => 'The referenced recommendation is invalid.',
            'idempotency_key.max' => 'The idempotency key is too long.',
        ];
    }

    /**
     * Laravel validates a FormRequest before the controller body runs, so the
     * framework default would emit a 422 with no `code` — unlike every other
     * error this API returns. Emit the shared VALIDATION_ERROR envelope with the
     * same correlation contract as the rest of the API.
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
