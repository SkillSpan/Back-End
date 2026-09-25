<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitBaselineAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /*
             * The FastAPI contract declares `responses` as an ARRAY of
             * objects: [{ "item_id": "sql-001", "answer": "B" }].
             *
             * `array` alone is NOT enough. A PHP associative array such as
             * ['q1' => 'a'] satisfies it and then json_encodes to the OBJECT
             * {"q1":"a"}, which the service rejects with 422. `list` requires
             * sequential integer keys from 0, so an object-shaped payload
             * fails here instead of at the wire.
             */
            'responses' => ['required', 'array', 'list', 'min:1'],
            'responses.*' => ['required', 'array'],
            'responses.*.item_id' => ['required', 'string', 'max:100'],
            'responses.*.answer' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * Reject a malformed `responses` entry before it reaches the service with
     * an opaque 422, so the caller learns which item is wrong.
     */
    public function messages(): array
    {
        return [
            'responses.list' => 'The responses field must be a JSON array of {item_id, answer} objects, not a JSON object.',
            'responses.*.item_id.required' => 'Each response requires an item_id.',
            'responses.*.answer.required' => 'Each response requires an answer.',
        ];
    }
}
