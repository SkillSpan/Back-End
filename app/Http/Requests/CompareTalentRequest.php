<?php

namespace App\Http\Requests;

use App\Services\Talent\TalentEngagementService;

/**
 * Compare candidates side by side.
 *
 * The bounds are the product decision, not a technical limit: two is the
 * minimum that makes a comparison a comparison, and five is as many columns as
 * a table can show before it stops being readable. The service enforces the
 * same ceiling for direct callers.
 */
class CompareTalentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'student_ids' => ['required', 'array', 'min:2', 'max:'.TalentEngagementService::MAX_COMPARE],
            'student_ids.*' => ['required', 'integer', 'gt:0', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'student_ids.min' => 'Choose at least two candidates to compare.',
            'student_ids.max' => 'You can compare up to '.TalentEngagementService::MAX_COMPARE.' candidates at a time.',
            'student_ids.*.distinct' => 'The same candidate cannot be compared twice.',
        ];
    }
}
