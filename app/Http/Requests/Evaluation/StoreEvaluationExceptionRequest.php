<?php

namespace App\Http\Requests\Evaluation;

use App\Models\EvaluationException as EvaluationExceptionRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEvaluationExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(EvaluationExceptionRecord::TYPES)],
            'subject_type' => ['required', 'string', 'max:100'],
            'subject_id' => ['required', 'integer', 'min:1'],
            'actor_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }
}
