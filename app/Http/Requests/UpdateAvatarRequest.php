<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'image',
                'mimes:jpeg,jpg,png,webp,gif',
                'max:'.(int) config('services.profile.avatar_max_upload_kb', 4096),
                'dimensions:min_width=32,min_height=32',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.required' => 'Choose an image to upload.',
            'avatar.image' => 'The file must be an image.',
            'avatar.mimes' => 'The image must be a JPEG, PNG, WebP or GIF.',
            'avatar.max' => 'The image may not be larger than '
                .(int) config('services.profile.avatar_max_upload_kb', 4096).' KB.',
            'avatar.dimensions' => 'The image must be at least 32x32 pixels.',
        ];
    }
}
