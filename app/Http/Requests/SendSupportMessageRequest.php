<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendSupportMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:4000'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'A message body is required.',
            'body.max' => 'A message may not be longer than 4000 characters.',
        ];
    }
}
