<?php

namespace App\Http\Requests\Evaluation;

use App\Models\ProfessionalRecordItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRecordVisibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['required', 'string', Rule::in(ProfessionalRecordItem::VISIBILITIES)],
        ];
    }
}
