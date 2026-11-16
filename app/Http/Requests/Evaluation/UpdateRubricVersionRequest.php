<?php

namespace App\Http\Requests\Evaluation;

use App\Models\Rubric;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRubricVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'min:3', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'applicable_project_types' => ['sometimes', 'nullable', 'array'],
            'applicable_project_types.*' => ['string', Rule::in(['simulation', 'company_sponsored'])],
            'score_min' => ['sometimes', 'nullable', 'numeric'],
            'score_max' => ['sometimes', 'nullable', 'numeric'],
            'dimensions' => ['sometimes', 'required', 'array', 'min:1', 'max:20'],
            'dimensions.*.name' => ['required_with:dimensions', 'string', Rule::in(Rubric::DIMENSIONS)],
            'dimensions.*.weight' => ['required_with:dimensions', 'numeric', 'min:0', 'max:1'],
            'dimensions.*.score_min' => ['sometimes', 'nullable', 'numeric'],
            'dimensions.*.score_max' => ['sometimes', 'nullable', 'numeric'],
            'dimensions.*.indicators' => ['sometimes', 'nullable', 'array'],
            'dimensions.*.indicators.*' => ['string', 'max:255'],
            'dimensions.*.skill_ids' => ['sometimes', 'nullable', 'array'],
            'dimensions.*.skill_ids.*' => ['integer', 'exists:skills,id'],
            'dimensions.*.order_index' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
