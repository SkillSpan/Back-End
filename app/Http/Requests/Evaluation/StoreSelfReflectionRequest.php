<?php

namespace App\Http\Requests\Evaluation;

use Illuminate\Foundation\Http\FormRequest;

class StoreSelfReflectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'answers' => ['sometimes', 'nullable', 'array', 'max:20'],
            'answers.*.question' => ['required_with:answers', 'string', 'max:500'],
            'answers.*.response' => ['required_with:answers', 'string', 'max:5000'],
            'learning_summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
