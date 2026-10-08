<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload (or replace) the panel user's avatar.
 *
 * The `image` rule is the first gate and the size ceiling is the second: the
 * bytes are stored inside the profile row, so an unbounded upload would be a way
 * for any panel user to bloat the table. `dimensions` rejects a file that decodes
 * but is too small to be a real avatar before any resampling happens.
 *
 * The accepted list is narrower than what the browser can preview on purpose —
 * SVG is excluded because it is a script container, not a bitmap, and rendering
 * a user-supplied one would be an XSS vector.
 *
 * AdminProfileService decodes and re-encodes the image again, so these rules
 * describe the contract rather than being the only thing standing between an
 * upload and the database.
 */
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
