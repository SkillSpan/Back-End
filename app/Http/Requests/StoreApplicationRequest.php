<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

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
