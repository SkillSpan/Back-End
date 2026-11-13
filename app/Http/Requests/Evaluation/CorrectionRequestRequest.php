<?php

namespace App\Http\Requests\Evaluation;

use Illuminate\Foundation\Http\FormRequest;

class CorrectionRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
