<?php

namespace App\Http\Requests\Evaluation;

use Illuminate\Foundation\Http\FormRequest;

class PublishRubricVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'effective_date' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
