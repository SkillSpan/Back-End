<?php

namespace App\Http\Requests\Evaluation;

use App\Models\ProfessionalRecordItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecordShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sections' => ['sometimes', 'nullable', 'array', 'max:'.count(ProfessionalRecordItem::SECTIONS)],
            'sections.*' => ['string', Rule::in(ProfessionalRecordItem::SECTIONS)],
            'days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
        ];
    }
}
