<?php

namespace App\Http\Requests;

use App\Services\Assistant\AssistantService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AskAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'intent' => ['required', 'string', Rule::in(AssistantService::ALLOWED_INTENTS)],
            'question' => ['required', 'string', 'min:3', 'max:2000'],
            'recommendation_id' => ['sometimes', 'nullable', 'integer', 'gt:0'],
            'project_id' => ['sometimes', 'nullable', 'integer', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'intent.required' => 'An assistant intent is required.',
            'intent.in' => 'The requested assistant intent is outside the permitted scope.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();
        $outOfScope = $errors->has('intent');

        $requestId = (string) ($this->header('X-Request-ID') ?: Str::uuid());

        throw new HttpResponseException(response()->json([
            'code' => $outOfScope ? 'ASSISTANT_INTENT_NOT_ALLOWED' : 'VALIDATION_ERROR',
            'message' => $outOfScope
                ? 'The requested assistant intent is outside the permitted scope.'
                : 'The request could not be processed.',
            'errors' => $errors,
            'request_id' => $requestId,
        ], 422, ['X-Request-ID' => $requestId]));
    }
}
