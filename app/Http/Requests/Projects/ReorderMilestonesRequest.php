<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

class ReorderMilestonesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'milestone_ids' => ['required', 'array', 'min:1'],
            'milestone_ids.*' => ['integer', 'distinct'],
        ];
    }
}
