<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TransitionProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to' => ['required', 'string', Rule::in(Project::STATUSES)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'to.required' => 'The target status is required.',
            'to.in' => 'That is not a project status.',
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
