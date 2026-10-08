<?php

namespace App\Http\Requests;

use App\Models\CareerRoleSkill;
use App\Models\Specialization;
use App\Services\Assessment\QuestionBankService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a question created from the admin "Questions" page.
 *
 * Beyond the per-field rules, it re-checks the Dynamic Assessment chain on
 * the SERVER — the career role must belong to the chosen specialization and
 * the skill must be required by that career role — so the panel's dynamic
 * dropdowns are a convenience, never the source of truth. A request that
 * pairs, say, Cybersecurity with Frontend Developer is rejected even if the
 * client posts it directly.
 */
class StoreQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route carries the `admin` middleware; authorization is not
        // re-decided here.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'specialization_id' => ['required', 'integer', 'exists:specializations,id'],
            'career_role_id' => ['required', 'integer', 'exists:career_roles,id'],
            'skill_id' => ['required', 'integer', 'exists:skills,id'],
            'item_type' => ['required', 'string', Rule::in(QuestionBankService::ANSWER_TYPES)],
            'question_text' => ['required', 'string', 'max:2000'],
            // Present only for multiple-choice questions; the shape rules
            // live in withValidator() so the conditional logic stays in one
            // readable place.
            'options' => ['sometimes', 'array', 'max:10'],
            'options.*' => ['nullable', 'string', 'max:255'],
            'correct_answer' => ['required', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateChain($validator);
            $this->validateAnswerShape($validator);
        });
    }

    /**
     * specialization -> career role -> skill must be a real, connected chain.
     */
    private function validateChain(Validator $validator): void
    {
        $specializationId = (int) $this->input('specialization_id');
        $careerRoleId = (int) $this->input('career_role_id');
        $skillId = (int) $this->input('skill_id');

        if (
            $specializationId > 0
            && $careerRoleId > 0
            && ! $validator->errors()->has('career_role_id')
        ) {
            $belongs = Specialization::find($specializationId)
                ?->careerRoles()
                ->whereKey($careerRoleId)
                ->exists() ?? false;

            if (! $belongs) {
                $validator->errors()->add(
                    'career_role_id',
                    'The selected career role does not belong to the selected specialization.',
                );
            }
        }

        if ($careerRoleId > 0 && $skillId > 0 && ! $validator->errors()->has('skill_id')) {
            $required = CareerRoleSkill::query()
                ->where('career_role_id', $careerRoleId)
                ->where('skill_id', $skillId)
                ->exists();

            if (! $required) {
                $validator->errors()->add(
                    'skill_id',
                    'The selected skill is not required by the selected career role.',
                );
            }
        }
    }

    /**
     * The answer must match the declared type: a multiple-choice question
     * needs at least two distinct options and a correct answer drawn from
     * them; a text question needs only the model answer.
     */
    private function validateAnswerShape(Validator $validator): void
    {
        if ($validator->errors()->has('item_type')) {
            return;
        }

        $type = (string) $this->input('item_type');
        $correct = trim((string) $this->input('correct_answer'));

        if ($type !== QuestionBankService::TYPE_MULTIPLE_CHOICE) {
            return; // text: correct_answer is the model answer, nothing more to check
        }

        $options = collect(is_array($this->input('options')) ? $this->input('options') : [])
            ->map(fn ($option) => trim((string) $option))
            ->filter(fn (string $option) => $option !== '')
            ->values();

        if ($options->count() < 2) {
            $validator->errors()->add(
                'options',
                'A multiple-choice question needs at least two options.',
            );

            return;
        }

        if ($options->count() !== $options->unique()->count()) {
            $validator->errors()->add('options', 'The options must be distinct.');
        }

        if ($correct !== '' && ! $options->contains($correct)) {
            $validator->errors()->add(
                'correct_answer',
                'The correct answer must be one of the options.',
            );
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'specialization_id.required' => 'A specialization is required.',
            'specialization_id.exists' => 'The selected specialization does not exist.',
            'career_role_id.required' => 'A career role is required.',
            'career_role_id.exists' => 'The selected career role does not exist.',
            'skill_id.required' => 'A skill is required.',
            'skill_id.exists' => 'The selected skill does not exist.',
            'item_type.required' => 'A question type is required.',
            'item_type.in' => 'The selected question type is not supported.',
            'question_text.required' => 'The question text is required.',
            'correct_answer.required' => 'An answer is required.',
        ];
    }
}
