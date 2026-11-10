<?php

namespace App\Http\Requests;

use App\Models\Specialization;
use Illuminate\Foundation\Http\FormRequest;

class ListCareerRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'specialization_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'exists:specializations,id',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_numeric($value)) {
                        return;
                    }

                    $isActive = Specialization::query()
                        ->whereKey((int) $value)
                        ->where('is_active', true)
                        ->exists();

                    if (! $isActive) {
                        $fail('The selected specialization is not available.');
                    }
                },
            ],
            'search' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'specialization_id.integer' => 'The specialization ID must be a positive integer.',
            'specialization_id.min' => 'The specialization ID must be a positive integer.',
            'specialization_id.exists' => 'The selected specialization does not exist.',
            'search.max' => 'The search term may not be longer than 120 characters.',
        ];
    }
}
