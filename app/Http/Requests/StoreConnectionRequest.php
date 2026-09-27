<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:users,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'initiated_by' => ['sometimes', 'string', 'in:mentor,student,admin'],
        ];
    }

    public function messages(): array
    {
        return [
            'student_id.required' => 'A student ID is required.',
            'student_id.exists' => 'The specified student does not exist.',
            'project_id.exists' => 'The specified project does not exist.',
        ];
    }
}
