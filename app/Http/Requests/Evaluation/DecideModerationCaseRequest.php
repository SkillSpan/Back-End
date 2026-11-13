<?php

namespace App\Http\Requests\Evaluation;

use App\Models\EvaluationDispute;
use App\Models\EvaluationModerationCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideModerationCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(EvaluationModerationCase::DECISIONS)],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'classification' => ['sometimes', 'nullable', 'string', Rule::in(EvaluationDispute::CLASSIFICATIONS)],
            'corrective_action' => ['sometimes', 'nullable', 'string', Rule::in(EvaluationDispute::CORRECTIVE_ACTIONS)],
            'outcome_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'resolution_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'replacement_evaluator_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ];
    }
}
