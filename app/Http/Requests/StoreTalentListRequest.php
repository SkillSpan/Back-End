<?php

namespace App\Http\Requests;

/**
 * Create a shortlist.
 *
 * The name is required because an unnamed list is indistinguishable from every
 * other unnamed list in a dropdown. The description is capped at 500 to match
 * the column, so an over-long one is a field error rather than a truncation the
 * user never sees.
 */
class StoreTalentListRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'The list needs a name.',
        ];
    }
}
