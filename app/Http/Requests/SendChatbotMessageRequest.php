<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendChatbotMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => [
                'required',
                'string',
                'max:'.(int) config('communication.message_max_length', 5000),
            ],
            'metadata' => ['sometimes', 'array'],
            'source' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
