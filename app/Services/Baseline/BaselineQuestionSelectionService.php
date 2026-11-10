<?php

namespace App\Services\Baseline;

use App\Exceptions\BaselineAssessmentException;
use App\Models\BaselineAssessmentItem;
use App\Models\BaselineQuestionSnapshot;
use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use Illuminate\Support\Collection;

class BaselineQuestionSelectionService
{
    public const DEFAULT_MIN_QUESTIONS_PER_SKILL = 1;

    public const DEFAULT_MAX_QUESTIONS_PER_SKILL = 3;

    public const DEFAULT_MAX_TOTAL_QUESTIONS = 30;

    public function select(
        CareerRole $careerRole,
        string $assessmentVersion
    ): Collection {
        $roleSkills = CareerRoleSkill::query()
            ->where('career_role_id', $careerRole->id)
            ->whereHas('skill', fn ($query) => $query->where('status', 'active'))
            ->with(['skill', 'prerequisites'])
            ->orderBy('skill_id')
            ->get();

        if ($roleSkills->isEmpty()) {
            throw BaselineAssessmentException::careerRoleHasNoSkills(
                (int) $careerRole->id
            );
        }

        $skillIds = $roleSkills->pluck('skill_id')->all();

        $itemsBySkill = BaselineAssessmentItem::query()
            ->where('assessment_version', $assessmentVersion)
            ->where('is_active', true)
            ->whereIn('skill_id', $skillIds)
            ->with('skill')
            ->get()
            ->groupBy(fn (BaselineAssessmentItem $item) => (int) $item->skill_id);

        $minQuestions = max(
            (int) config(
                'services.baseline_assessment.min_questions_per_skill',
                self::DEFAULT_MIN_QUESTIONS_PER_SKILL
            ),
            1,
        );

        $maxQuestions = max(
            (int) config(
                'services.baseline_assessment.max_questions_per_skill',
                self::DEFAULT_MAX_QUESTIONS_PER_SKILL
            ),
            $minQuestions,
        );

        $maxTotalQuestions = max(
            (int) config(
                'services.baseline_assessment.max_total_questions',
                self::DEFAULT_MAX_TOTAL_QUESTIONS
            ),
            $minQuestions,
        );

        $deterministic = (bool) config(
            'services.baseline_assessment.deterministic_selection',
            false
        );

        $uncoveredSkills = [];
        $selected = collect();

        foreach ($roleSkills as $roleSkill) {
            $skillId = (int) $roleSkill->skill_id;

            $skillItems = $itemsBySkill->get($skillId, collect());

            if ($skillItems->isEmpty()) {
                $uncoveredSkills[] = [
                    'skill_id' => $skillId,
                    'skill_name' => $roleSkill->skill?->name,
                    'slug' => $roleSkill->skill?->slug,
                ];

                continue;
            }

            $limit = min(max($skillItems->count(), $minQuestions), $maxQuestions);

            $pool = $deterministic
                ? $skillItems->sortBy('item_id')->values()
                : $skillItems->shuffle()->values();

            $prerequisiteSkillIds = $roleSkill->prerequisites
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            foreach ($pool->take($limit) as $item) {
                $selected->push([
                    'item_id' => (string) $item->item_id,
                    'baseline_assessment_item_id' => (int) $item->id,
                    'skill_id' => $skillId,
                    'skill_slug' => $item->skill?->slug,
                    'skill_name' => $item->skill?->name,
                    'item_type' => (string) $item->item_type,
                    'question_text' => $item->question_text,
                    'options' => is_array($item->options) ? array_values($item->options) : [],
                    'correct_answer' => $item->correct_answer,
                    'scoring_rule' => is_array($item->scoring_rule) ? $item->scoring_rule : null,
                    'weight' => (float) $item->weight,
                    'importance_weight' => (float) $roleSkill->importance_weight,
                    'required_level' => (float) $roleSkill->required_level,
                    'is_critical' => (bool) $roleSkill->is_critical,
                    'prerequisite_skill_ids' => $prerequisiteSkillIds,
                    'prerequisite_slugs' => $roleSkill->prerequisites
                        ->pluck('slug')
                        ->values()
                        ->all(),
                    'prerequisite_names' => $roleSkill->prerequisites
                        ->pluck('name')
                        ->values()
                        ->all(),
                ]);
            }
        }

        if ($uncoveredSkills !== []) {
            throw BaselineAssessmentException::insufficientQuestionCoverage(
                $uncoveredSkills
            );
        }

        $this->assertQuestionContentIsValid($selected);

        $distinctSkills = $selected
            ->pluck('skill_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->count();

        if ($distinctSkills > $maxTotalQuestions) {
            throw BaselineAssessmentException::insufficientQuestionCapacity(
                $distinctSkills,
                $maxTotalQuestions,
            );
        }

        if ($selected->count() > $maxTotalQuestions) {
            $selected = $this->capPreservingCoverage($selected, $maxTotalQuestions);
        }

        return $selected->values();
    }

    private function assertQuestionContentIsValid(Collection $selected): void
    {
        $invalid = [];

        foreach ($selected as $question) {
            $problems = [];

            $text = $question['question_text'];
            if (! is_string($text) || trim($text) === '') {
                $problems[] = 'missing_question_text';
            }

            $options = $question['options'];
            $distinctOptions = array_unique(
                array_map('strval', is_array($options) ? $options : [])
            );

            if ($question['item_type'] === 'single_choice') {
                if (count($distinctOptions) < 2) {
                    $problems[] = 'insufficient_distinct_options';
                }
            } elseif ($question['item_type'] === 'text') {
            } elseif ($distinctOptions === []) {
                $problems[] = 'missing_options';
            }

            if ($problems !== []) {
                $invalid[] = [
                    'item_id' => $question['item_id'],
                    'skill_id' => (int) $question['skill_id'],
                    'item_type' => $question['item_type'],
                    'problems' => $problems,
                ];
            }
        }

        if ($invalid !== []) {
            throw BaselineAssessmentException::invalidQuestionContent($invalid);
        }
    }

    private function capPreservingCoverage(Collection $selected, int $maxTotalQuestions): Collection
    {
        $groups = $selected
            ->groupBy('skill_id')
            ->map(function (Collection $rows) {
                return $rows->sortBy('item_id')->values();
            })
            ->sortByDesc(fn (Collection $rows) => [
                (int) $rows->first()['is_critical'],
                (float) $rows->first()['importance_weight'],
            ]);

        $kept = [];

        foreach ($groups as $skillId => $rows) {
            if (count($kept) >= $maxTotalQuestions) {
                break;
            }

            $kept[] = $rows->first();
        }

        $cursor = 1;

        while (count($kept) < $maxTotalQuestions) {
            $added = false;

            foreach ($groups as $rows) {
                if (count($kept) >= $maxTotalQuestions) {
                    break;
                }

                if ($rows->count() > $cursor) {
                    $kept[] = $rows[$cursor];
                    $added = true;
                }
            }

            if (! $added) {
                break;
            }

            $cursor++;
        }

        return collect($kept)->values();
    }

    public function validateResponsesAgainstSnapshot(
        Collection $snapshots,
        array $responses
    ): void {
        if ($snapshots->isEmpty()) {
            throw new BaselineAssessmentException(
                'This baseline assessment has no question snapshot and cannot be submitted.',
                409,
                'ASSESSMENT_SNAPSHOT_MISSING',
            );
        }

        $allowed = $snapshots->mapWithKeys(function ($snapshot) {
            return [
                strtolower($this->snapshotItemId($snapshot)) => $snapshot,
            ];
        });

        $seenQuestionIds = [];

        foreach ($responses as $index => $response) {
            if (! is_array($response)) {
                throw new BaselineAssessmentException(
                    "Response at index {$index} is malformed.",
                    422,
                    'INVALID_RESPONSE_FORMAT',
                    ['index' => $index],
                );
            }

            $questionId = $response['question_id'] ?? null;

            if ($questionId === null || trim((string) $questionId) === '') {
                throw new BaselineAssessmentException(
                    "Response at index {$index} is missing a question_id.",
                    422,
                    'INVALID_RESPONSE_FORMAT',
                    ['index' => $index],
                );
            }

            $normalizedQuestionId = strtolower((string) $questionId);

            if (! $allowed->has($normalizedQuestionId)) {
                throw new BaselineAssessmentException(
                    'Response references a question that is not part of this assessment.',
                    422,
                    'UNAUTHORIZED_QUESTION',
                    ['question_id' => $questionId],
                );
            }

            if (in_array($normalizedQuestionId, $seenQuestionIds, true)) {
                throw new BaselineAssessmentException(
                    "Duplicate response for question_id: {$questionId}.",
                    422,
                    'DUPLICATE_RESPONSE',
                    ['question_id' => $questionId],
                );
            }

            $seenQuestionIds[] = $normalizedQuestionId;

            if (! array_key_exists('answer', $response)) {
                throw new BaselineAssessmentException(
                    "Response at index {$index} is missing an answer.",
                    422,
                    'INVALID_RESPONSE_FORMAT',
                    ['index' => $index, 'question_id' => $questionId],
                );
            }

            $snapshot = $allowed->get($normalizedQuestionId);

            $itemType = $this->snapshotItemType($snapshot);

            if ($itemType !== 'single_choice') {
                continue;
            }

            $options = $this->snapshotOptions($snapshot);

            if ($options === []) {
                throw new BaselineAssessmentException(
                    "The answer for question_id {$questionId} cannot be validated: question options are unavailable.",
                    422,
                    'INVALID_ANSWER_OPTION',
                    ['question_id' => $questionId],
                );
            }

            $allowedOptions = array_map('strval', $options);
            $allowedOptionsLower = array_map('strtolower', $allowedOptions);

            if (! in_array(strtolower((string) $response['answer']), $allowedOptionsLower, true)) {
                throw new BaselineAssessmentException(
                    "The answer for question_id {$questionId} is not an allowed option.",
                    422,
                    'INVALID_ANSWER_OPTION',
                    [
                        'question_id' => $questionId,
                        'allowed_options' => array_values($allowedOptions),
                    ],
                );
            }
        }
    }

    public function validateCompleteness(Collection $snapshots, array $responses): void
    {
        $expected = $snapshots
            ->map(fn ($snapshot) => strtolower($this->snapshotItemId($snapshot)))
            ->values()
            ->all();

        $provided = array_map(
            fn ($response) => strtolower((string) ($response['question_id'] ?? '')),
            $responses,
        );

        $missing = array_values(array_diff($expected, $provided));

        if ($missing !== []) {
            throw new BaselineAssessmentException(
                'The assessment is missing responses for one or more questions.',
                422,
                'INCOMPLETE_RESPONSES',
                ['missing_question_ids' => $missing],
            );
        }
    }

    public function snapshotItemId(BaselineQuestionSnapshot $snapshot): string
    {
        if (is_string($snapshot->item_id) && $snapshot->item_id !== '') {
            return $snapshot->item_id;
        }

        return (string) ($snapshot->item?->item_id ?? '');
    }

    public function snapshotItemType(BaselineQuestionSnapshot $snapshot): string
    {
        if (is_string($snapshot->item_type) && $snapshot->item_type !== '') {
            return $snapshot->item_type;
        }

        $live = $snapshot->item?->item_type;

        return is_string($live) && $live !== '' ? $live : 'single_choice';
    }

    public function snapshotOptions(BaselineQuestionSnapshot $snapshot): array
    {
        $frozen = $snapshot->options;

        if (is_array($frozen) && $frozen !== []) {
            return array_values(array_map('strval', $frozen));
        }

        $live = $snapshot->item?->options;

        return is_array($live) && $live !== []
            ? array_values(array_map('strval', $live))
            : [];
    }

    public function snapshotQuestionText(BaselineQuestionSnapshot $snapshot): ?string
    {
        $frozen = $snapshot->question_text;

        if (is_string($frozen) && $frozen !== '') {
            return $frozen;
        }

        $live = $snapshot->item?->question_text;

        return is_string($live) && $live !== '' ? $live : null;
    }
}
