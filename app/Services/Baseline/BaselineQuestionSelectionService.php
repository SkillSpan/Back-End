<?php

namespace App\Services\Baseline;

use App\Exceptions\BaselineAssessmentException;
use App\Models\BaselineAssessmentItem;
use App\Models\BaselineQuestionSnapshot;
use App\Models\CareerRole;
use App\Models\CareerRoleSkill;
use Illuminate\Support\Collection;

/**
 * Selects the set of baseline assessment questions for a learner's
 * chosen career role.
 *
 * Responsibilities:
 *  - resolve the role's required skills, weights, critical flags and
 *    prerequisites (requirement 1);
 *  - pick active questions mapped to those skills, honouring the
 *    per-skill min/max configuration so the question count is variable
 *    but always fully covers every required skill (requirement 2);
 *  - raise INSUFFICIENT_QUESTION_COVERAGE — not a partial assessment —
 *    when any required skill has no active mapped question.
 *
 * The service is deliberately read-only: it never writes assessment
 * state. Persistence and snapshot creation live in
 * BaselineAssessmentService so selection can be re-run/unit-tested
 * without side effects.
 */
class BaselineQuestionSelectionService
{
    public const DEFAULT_MIN_QUESTIONS_PER_SKILL = 1;

    public const DEFAULT_MAX_QUESTIONS_PER_SKILL = 3;

    public const DEFAULT_MAX_TOTAL_QUESTIONS = 30;

    /**
     * @return Collection<int, array{
     *     item_id: string,
     *     baseline_assessment_item_id: int,
     *     skill_id: int,
     *     skill_slug: ?string,
     *     skill_name: ?string,
     *     item_type: string,
     *     question_text: ?string,
     *     options: array,
     *     importance_weight: float,
     *     required_level: float,
     *     is_critical: bool,
     *     prerequisite_skill_ids: array<int>,
     *     prerequisite_slugs: array<int, string>,
     *     prerequisite_names: array<int, string>
     * }>
     *
     * @throws BaselineAssessmentException
     */
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

            /** @var Collection<int, BaselineAssessmentItem> $skillItems */
            $skillItems = $itemsBySkill->get($skillId, collect());

            if ($skillItems->isEmpty()) {
                $uncoveredSkills[] = [
                    'skill_id' => $skillId,
                    'skill_name' => $roleSkill->skill?->name,
                    'slug' => $roleSkill->skill?->slug,
                ];

                continue;
            }

            // Variable count: use as many active items as the role skill
            // exposes, bounded by [min, max]. A skill with a single active
            // question still gets one — coverage is the contract, not the
            // exact count.
            $limit = min(max($skillItems->count(), $minQuestions), $maxQuestions);

            // Shuffle by default so consecutive attempts differ; the
            // deterministic flag (used by tests and reproducible runs)
            // instead sorts by item_id for a stable, repeatable selection.
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

        /*
         * Total cap — coverage is a hard constraint.
         *
         * The cap must never silently drop a required skill: an assessment
         * that reports full coverage but never actually probes one of the
         * role's skills would produce a readiness score the evidence
         * cannot support.
         *
         * The per-skill floor (min_questions_per_skill, >= 1) guarantees
         * every required skill has at least one question, so the smallest
         * coverage-preserving assessment is `skillCount` questions. If
         * that floor exceeds the configured cap the configuration is
         * self-contradictory and we return an explicit error rather than
         * silently under-covering the role.
         */
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

    /**
     * Trim a fully-covering selection down to the total cap without ever
     * removing the last question of a skill.
     *
     * Two passes:
     *  1. give every skill its single coverage question (critical first,
     *     then by descending importance weight);
     *  2. spend the remaining budget on additional questions, preferring
     *     critical / heavier skills, so the cap is used where it matters
     *     most instead of being filled arbitrarily.
     *
     * @param  Collection<int, array<string, mixed>>  $selected
     * @return Collection<int, array<string, mixed>>
     */
    private function capPreservingCoverage(Collection $selected, int $maxTotalQuestions): Collection
    {
        $groups = $selected
            ->groupBy('skill_id')
            ->map(function (Collection $rows) {
                // Deterministic ordering inside a skill keeps the cap
                // reproducible regardless of the earlier shuffle.
                return $rows->sortBy('item_id')->values();
            })
            // Heaviest / most critical skills are served their extra
            // questions first when the budget is scarce.
            ->sortByDesc(fn (Collection $rows) => [
                (int) $rows->first()['is_critical'],
                (float) $rows->first()['importance_weight'],
            ]);

        $kept = [];

        // Pass 1 — one guaranteed question per skill (coverage floor).
        foreach ($groups as $skillId => $rows) {
            if (count($kept) >= $maxTotalQuestions) {
                break;
            }

            $kept[] = $rows->first();
        }

        // Pass 2 — spend the remaining budget on extra questions.
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

            // No skill has any remaining question -> budget cannot be spent.
            if (! $added) {
                break;
            }

            $cursor++;
        }

        return collect($kept)->values();
    }

    /**
     * Validate a submission against the assessment's immutable snapshot.
     *
     * Enforces, in order: response shape, question membership (the
     * question must belong to THIS assessment's snapshot), duplicate
     * responses for the same question, presence of an answer, and — for
     * `single_choice` items — that the answer is one of the item's
     * allowed options.
     *
     * NOTE: `scale` items intentionally skip the allowed-option check.
     * Their `options` column holds the scale anchors (e.g. "1".."5") and
     * the intelligence service accepts the raw self-rated value, so
     * constraining the answer to the anchors would reject valid
     * submissions. Mirrors the existing contract exercised by
     * BaselineAssessmentTest.
     *
     * @param  Collection<int, BaselineQuestionSnapshot>  $snapshots
     *
     * @throws BaselineAssessmentException
     */
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

            /** @var BaselineQuestionSnapshot $snapshot */
            $snapshot = $allowed->get($normalizedQuestionId);

            // Content is read from the FROZEN snapshot first, never from
            // the mutable live item row. A later bank edit (option list
            // change, deactivation, deletion) must not alter how an
            // already-started assessment validates its answers.
            // Legacy snapshots predate content freezing and fall back to
            // the live item; entirely missing content fails closed.
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

    /**
     * Missing responses: every question in the snapshot must have exactly
     * one response before the assessment may be completed.
     *
     * @param  Collection<int, BaselineQuestionSnapshot>  $snapshots
     *
     * @throws BaselineAssessmentException
     */
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

    /*
    |--------------------------------------------------------------------------
    | Frozen-content accessors
    |--------------------------------------------------------------------------
    |
    | These read the immutable snapshot columns first and only fall back to
    | the live baseline_assessment_items row for snapshots created before
    | content freezing was introduced. Anything still unavailable is
    | treated as missing (fail closed) rather than defaulted.
    */

    /**
     * Stable client-facing question identifier for a snapshot row.
     */
    public function snapshotItemId(BaselineQuestionSnapshot $snapshot): string
    {
        if (is_string($snapshot->item_id) && $snapshot->item_id !== '') {
            return $snapshot->item_id;
        }

        return (string) ($snapshot->item?->item_id ?? '');
    }

    /**
     * `single_choice` | `scale`. Unknown/missing is treated as
     * `single_choice` so option validation stays on (fail closed).
     */
    public function snapshotItemType(BaselineQuestionSnapshot $snapshot): string
    {
        if (is_string($snapshot->item_type) && $snapshot->item_type !== '') {
            return $snapshot->item_type;
        }

        $live = $snapshot->item?->item_type;

        return is_string($live) && $live !== '' ? $live : 'single_choice';
    }

    /**
     * @return array<int, string>
     */
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

    /**
     * The authored prompt. Null when none was authored — we never invent
     * question text.
     */
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
