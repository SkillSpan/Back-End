<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * Clone a project.
 *
 * Everything is optional: the endpoint's whole point is "copy what exists".
 * Only the fields a re-run genuinely has to move are accepted, so `clone`
 * cannot be used as a back door to rewrite a project the actor may not edit —
 * `ProjectLifecycleService::clone()` copies the rest from the source row.
 *
 * Date ORDERING is not validated here for the same reason it is not on create:
 * a partial payload is compared against the stored values inside the service,
 * where the final state is known.
 */
class CloneProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'application_deadline' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'capacity.min' => 'A project must have room for at least one participant.',
        ];
    }

    /**
     * Laravel validates before the controller body runs, so the framework
     * default would emit a 422 with no `code`. Emit the shared envelope.
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
