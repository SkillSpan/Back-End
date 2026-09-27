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
            'responses' => ['required', 'array', 'min:1'],
            'responses.*.question_id' => ['required', 'string'],
            'responses.*.answer' => ['required'],
        ];
    }

    public function messages(): array
    {
        return [
            'responses.required' => 'Assessment responses are required.',
            'responses.min' => 'At least one response is required.',
            'responses.*.question_id.required' => 'Each response must specify a question_id.',
            'responses.*.question_id.string' => 'Each question_id must be a string.',
            'responses.*.answer.required' => 'Each response must include an answer.',
        ];
    }
}
