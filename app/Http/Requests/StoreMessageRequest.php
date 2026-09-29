<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxLength = (int) config('communication.message_max_length', 5000);

        return [
            'body' => ['required', 'string', 'max:'.$maxLength],
            'message_type' => ['sometimes', 'string', 'in:text,system,chatbot'],
            'metadata' => ['sometimes', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'Message body is required.',
            'body.max' => 'Message exceeds the maximum allowed length.',
        ];
    }
}
