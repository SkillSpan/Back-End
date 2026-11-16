<?php

namespace App\Http\Requests\Projects;

use App\Models\ProjectMilestone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMilestoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'min:3', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'required_deliverables' => ['sometimes', 'nullable', 'array', 'max:20'],
            'required_deliverables.*' => ['string', 'max:255'],
            'submission_type' => ['sometimes', 'nullable', 'string', Rule::in(ProjectMilestone::SUBMISSION_TYPES)],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'effective_deadline' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(ProjectMilestone::STATUSES)],
            'order_index' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
