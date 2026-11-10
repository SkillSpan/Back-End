<?php

namespace App\Http\Requests;

use App\Services\Talent\TalentEngagementService;

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
