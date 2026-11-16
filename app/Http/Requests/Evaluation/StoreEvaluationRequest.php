<?php

namespace App\Http\Requests\Evaluation;

use Illuminate\Foundation\Http\FormRequest;

class StoreEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'task_id' => ['required_without:participant_id', 'nullable', 'integer', 'min:1'],
            'project_id' => ['required_without:task_id', 'nullable', 'integer', 'min:1'],
            'participant_id' => ['required_without:task_id', 'nullable', 'integer', 'min:1'],
        ];
    }
}
