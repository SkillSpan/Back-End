<?php

namespace App\Http\Requests;

use App\Services\Assistant\AssistantService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * US-REC-01 — assistant question validation.
 *
 * The intent whitelist is the gateway for the assistant's permitted scope
 * (SRS v1.1 §12.5). A request whose intent falls outside it is rejected
 * here — before the orchestration layer, and before FastAPI is ever
 * contacted.
 *
 * Optional `recommendation_id` / `project_id` are validated only for
 * shape here. Whether the learner is actually allowed to see them is an
 * authorisation question and is answered by AssistantService (BR-11).
 */
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

    /**
     * Laravel validates a FormRequest during route resolution — before the
     * controller body runs — so the controller can never translate this
     * failure, and the framework default would return a 422 carrying no
     * `code` at all, unlike every other error this API emits.
     *
     * A refusal on `intent` is a scope decision (§12.5 / BR-01), not a
     * malformed payload, so it gets its own stable code. An absent intent
     * maps to the same code: by definition it is not a permitted intent.
     */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();
        $outOfScope = $errors->has('intent');

        // Same correlation contract as AssistantController: reuse the
        // incoming X-Request-ID when present, otherwise generate one.
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
