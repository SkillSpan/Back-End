<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * A FormRequest whose failures use the API's own envelope.
 *
 * Laravel validates before the controller body runs, so the framework default
 * would emit a 422 whose body has `message` and `errors` but no `code` — unlike
 * every other error this API returns. Each existing request repeated the same
 * twelve lines to fix that; new ones extend this instead.
 *
 * The older requests are left as they are: rewriting them would touch files
 * that already work, for no behavioural gain.
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
