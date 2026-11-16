<?php

namespace App\Http\Requests\Projects;

use App\Models\Submission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxKb = (int) config('sprint5.submissions.max_file_kb', 20480);

        return [
            'submission_type' => ['required', 'string', Rule::in(Submission::TYPES)],
            'project_milestone_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'link_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:120'],
            'file' => ['sometimes', 'nullable', 'file', 'max:'.$maxKb],
        ];
    }
}
