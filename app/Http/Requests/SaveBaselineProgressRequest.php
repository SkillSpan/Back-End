<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveBaselineProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'progress' => ['nullable', 'array'],
            'responses' => ['nullable', 'array'],
        ];
    }
}
