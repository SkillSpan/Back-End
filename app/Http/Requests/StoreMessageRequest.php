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

            /*
             * `text` and `chatbot` — `system` removed.
             *
             * A participant legitimately tags their own message `chatbot` (a
             * mentor sending an automated-style reminder; covered by
             * ConversationTest::test_send_message_with_chatbot_type), and the
             * chatbot endpoint mirrors that by forcing `chatbot` and refusing to
             * read the field from the client.
             *
             * `system` is different. Nothing server-side ever writes a `system`
             * row into `messages` — that type belongs to `support_messages`, a
             * different table — and Message::scopeText() exists precisely to
             * separate machine-authored rows from human ones. Accepting it here
             * therefore had no legitimate caller and let any participant author
             * a message the UI renders as a platform notice. It is refused
             * (422) rather than silently downgraded, so the caller is told.
             */
            'message_type' => ['sometimes', 'string', 'in:text,chatbot'],
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
