<?php

namespace App\Http\Requests\Projects;

use App\Models\MilestoneExtensionRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideExtensionRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in([
                MilestoneExtensionRequest::STATUS_APPROVED,
                MilestoneExtensionRequest::STATUS_REJECTED,
            ])],
            'new_due_date' => ['sometimes', 'nullable', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000', 'required_if:decision,rejected'],
        ];
    }
}
