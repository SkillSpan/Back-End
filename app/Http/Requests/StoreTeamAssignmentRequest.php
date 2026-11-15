<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

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
