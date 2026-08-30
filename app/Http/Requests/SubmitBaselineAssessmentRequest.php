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
            'responses' => ['required', 'array'],
        ];
    }
}
