<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a chatbot message submission. `message_type` is deliberately
 * NOT accepted from the client — the chatbot endpoint always writes
 * type='chatbot', so allowing the caller to set it would let them
 * impersonate a human 'text' message.
 */
class SendChatbotMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
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
