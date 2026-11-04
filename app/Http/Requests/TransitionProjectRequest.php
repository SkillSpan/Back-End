<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The generic lifecycle move: `POST /projects/{project}/transitions`.
 *
 * `to` is validated against the twelve real statuses, so a typo is a 422 with a
 * field error rather than a silent no-op. WHICH of those twelve are reachable
 * from the project's current state is a different question and is answered by
 * `ProjectLifecycleService::EXECUTABLE_TRANSITIONS` — the service refuses an
 * unreachable target with `PROJECT_INVALID_TRANSITION` and lists the ones that
 * would work.
 *
 * Splitting it that way keeps one source of truth: the request knows the
 * vocabulary, the service knows the graph.
 */
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
