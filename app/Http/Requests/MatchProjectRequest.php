<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MatchProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['project' => $this->route('project')]);
    }

    public function rules(): array
    {
        return [
            'project' => ['required', 'integer', 'gt:0'],
        ];
    }
}
