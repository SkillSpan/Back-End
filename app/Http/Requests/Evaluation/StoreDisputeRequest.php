<?php

namespace App\Http\Requests\Evaluation;

use Illuminate\Foundation\Http\FormRequest;

class StoreDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'grounds' => ['required', 'string', 'min:20', 'max:5000'],
            'disputed_criteria' => ['sometimes', 'nullable', 'array', 'max:20'],
            'disputed_criteria.*' => ['string', 'max:100'],
            'evidence' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'exception_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
