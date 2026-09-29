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
            'responses' => ['required', 'array', 'list', 'min:1'],
            'responses.*' => ['required', 'array'],
            'responses.*.question_id' => [
                'required',
                'string',
                'max:100',
            ],
            'responses.*.answer' => ['required'],
        ];
    }

    public function messages(): array
    {
        return [
            'responses.required' => 'Assessment responses are required.',
            'responses.array' => 'Responses must be an array.',
            'responses.list' => 'Responses must be a JSON array of objects.',
            'responses.min' => 'At least one response is required.',
            'responses.*.question_id.required' => 'Each response must specify a question_id.',
            'responses.*.question_id.string' => 'Each question_id must be a string.',
            'responses.*.answer.required' => 'Each response must include an answer.',
        ];
    }
}
