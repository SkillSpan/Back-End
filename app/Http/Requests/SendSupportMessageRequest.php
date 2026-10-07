<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A message in a support thread — from the learner or from a support person.
 *
 * One request class for both directions: the body rules are identical, and the
 * controller decides who is sending from the route it is on. Splitting it in two
 * would duplicate the rules and let the two drift.
 */
class SendSupportMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:4000'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'A message body is required.',
            'body.max' => 'A message may not be longer than 4000 characters.',
        ];
    }
}
