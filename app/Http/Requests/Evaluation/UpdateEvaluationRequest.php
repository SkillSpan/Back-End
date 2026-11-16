<?php

namespace App\Http\Requests\Evaluation;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dimension_scores' => ['sometimes', 'array', 'max:20'],
            'dimension_scores.*.rubric_dimension_id' => ['required_with:dimension_scores', 'integer', 'min:1'],
            'dimension_scores.*.score' => ['required_with:dimension_scores', 'numeric'],
            'dimension_scores.*.comment' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'team_outcome' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'individual_contribution' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'improvement_guidance' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'confidential_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
