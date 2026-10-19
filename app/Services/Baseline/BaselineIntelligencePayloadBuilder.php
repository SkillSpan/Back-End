<?php

namespace App\Services\Baseline;

use App\Exceptions\BaselineAssessmentException;
use App\Models\BaselineAssessment;
use App\Models\BaselineAssessmentItem;
use App\Models\BaselineQuestionSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Builds the EXACT request body the Intelligence (FastAPI) baseline endpoint
 * accepts, from the assessment's frozen snapshot.
 *
 * Contract (source of truth: the FastAPI `BaselineRequest` schema):
 *
 *   questions[]: { question_id:int, question_type:"single_choice"|"scale",
 *                  options:string[], correct_answer:string|null,
 *                  scoring_rule:dict<string,float>|null, weight:float,
 *                  skill_mappings:[{skill_id:int, skill_slug:string, weight:float}] }
 *   responses[]: { question_id:int, answer:string }
 *
 * Identifier rule: the public/client identifier (`item_id`, e.g. "sql-001")
 * stays a frontend concern. Everything sent upstream uses
 * `baseline_assessment_item_id` — the numeric `baseline_assessment_items`
 * primary key — and it is a real PHP int, never a numeric string.
 *
 * Everything is read from the snapshot, not from the live question bank: the
 * learner must be scored against the questions and scoring keys that were
 * frozen when the assessment started. Snapshots created before scoring was
 * frozen are handled by a strictly-verified fallback (see
 * {@see resolveScoring()}); anything that cannot be proven correct fails
 * closed.
 */
class BaselineIntelligencePayloadBuilder
{
    /** The only question types the Intelligence baseline contract accepts. */
    public const SUPPORTED_QUESTION_TYPES = ['single_choice', 'scale'];

    public const TYPE_SINGLE_CHOICE = 'single_choice';

    public const TYPE_SCALE = 'scale';

    /**
     * Tolerance for floating-point weight sums (1.0 expected).
     */
    private const WEIGHT_EPSILON = 0.0001;

    public function __construct(
        private readonly BaselineQuestionSelectionService $questionSelection,
    ) {}

    /**
     * @param  Collection<int, BaselineQuestionSnapshot>  $snapshots
     * @param  array<int, array<string, mixed>>  $responses  Frontend responses (string item_ids).
     * @return array{questions: array<int, array<string, mixed>>, responses: array<int, array<string, mixed>>}
     *
     * @throws BaselineAssessmentException
     */
    public function build(
        BaselineAssessment $assessment,
        Collection $snapshots,
        array $responses
    ): array {
        $snapshots = $snapshots->values();

        if ($snapshots->isEmpty()) {
            throw BaselineAssessmentException::snapshotIncomplete([], ['snapshot_empty']);
        }

        $questions = [];
        $questionIdByItemId = [];

        foreach ($snapshots as $snapshot) {
            $itemId = $this->questionSelection->snapshotItemId($snapshot);

            if ($itemId === '') {
                throw BaselineAssessmentException::snapshotIncomplete(
                    [(int) $snapshot->id],
                    ['missing_item_id'],
                );
            }

            // `baseline_question_snapshots.id` is a snapshot row id — NOT the
            // identifier the contract wants. Only the assessment item id is.
            $questionId = $snapshot->baseline_assessment_item_id;

            if ($questionId === null || (int) $questionId <= 0) {
                // Happens for legacy rows detached by ON DELETE SET NULL.
                throw BaselineAssessmentException::snapshotIncomplete(
                    [(int) $snapshot->id],
                    ['missing_baseline_assessment_item_id'],
                );
            }

            $questionId = (int) $questionId;

            $questionType = $this->questionSelection->snapshotItemType($snapshot);

            if (! in_array($questionType, self::SUPPORTED_QUESTION_TYPES, true)) {
                // `text` exists in the Laravel bank but is not part of the
                // Intelligence baseline contract. Never coerce it and never
                // send it to collect an opaque upstream 422.
                throw BaselineAssessmentException::unsupportedQuestionType([[
                    'item_id' => $itemId,
                    'item_type' => $questionType,
                    'supported_types' => self::SUPPORTED_QUESTION_TYPES,
                ]]);
            }

            $scoring = $this->resolveScoring($assessment, $snapshot, $questionType);

            $questions[] = [
                'question_id' => $questionId,
                'question_type' => $questionType,
                'options' => $this->normalizeOptions(
                    $this->questionSelection->snapshotOptions($snapshot)
                ),
                'correct_answer' => $scoring['correct_answer'],
                'scoring_rule' => $scoring['scoring_rule'],
                'weight' => $scoring['weight'],
                'skill_mappings' => [[
                    'skill_id' => (int) $snapshot->skill_id,
                    'skill_slug' => $scoring['skill_slug'],
                    // One baseline item maps to exactly one skill in the
                    // current design, so the single mapping carries all of it.
                    'weight' => 1.0,
                ]],
            ];

            // Case-insensitive because the client identifier is opaque and the
            // existing response validation already compares it that way.
            $questionIdByItemId[strtolower($itemId)] = $questionId;
        }

        $mappedResponses = [];

        foreach ($responses as $index => $response) {
            $rawId = is_array($response) ? ($response['question_id'] ?? null) : null;
            $key = strtolower(trim((string) $rawId));

            if ($key === '' || ! isset($questionIdByItemId[$key])) {
                // Should be unreachable: membership was already validated
                // against the same snapshot. Fail closed rather than drop it.
                throw BaselineAssessmentException::invalidIntelligencePayload([
                    "responses.{$index}.question_id",
                ]);
            }

            $mappedResponses[] = [
                'question_id' => $questionIdByItemId[$key],
                'answer' => (string) ($response['answer'] ?? ''),
            ];
        }

        $payload = [
            'questions' => $questions,
            'responses' => $mappedResponses,
        ];

        $this->assertContract($payload);

        return $payload;
    }

    /**
     * Resolve the scoring inputs for one snapshot.
     *
     * Preferred path: the values frozen at assessment start.
     *
     * Legacy path (rows created before scoring was frozen): fall back to the
     * live item ONLY when every frozen value we do have still matches it. If
     * the item was edited, detached or deleted, fail closed — a score derived
     * from a snapshot we cannot verify is worse than no score.
     *
     * @return array{correct_answer: ?string, scoring_rule: ?array<string, float>, weight: float, skill_slug: string}
     *
     * @throws BaselineAssessmentException
     */
    private function resolveScoring(
        BaselineAssessment $assessment,
        BaselineQuestionSnapshot $snapshot,
        string $questionType
    ): array {
        $hasFrozenScoring = $snapshot->weight !== null
            || $snapshot->skill_slug !== null
            || $snapshot->correct_answer !== null
            || $snapshot->scoring_rule !== null;

        if ($hasFrozenScoring) {
            // Identity/weight must be frozen for the payload to be
            // attributable to this snapshot. A missing type-specific grading
            // value (correct_answer for single_choice, scoring_rule for scale)
            // is reported by the contract validation below instead, so the
            // error names the actual field.
            if ($snapshot->weight === null
                || ! is_numeric($snapshot->weight)
                || ! is_string($snapshot->skill_slug)
                || trim($snapshot->skill_slug) === '') {
                throw BaselineAssessmentException::snapshotIncomplete(
                    [(int) $snapshot->id],
                    ['incomplete_frozen_scoring'],
                );
            }

            return [
                // Passed through as frozen; the contract validation below
                // rejects a value that is invalid for the question type
                // instead of silently dropping it.
                'correct_answer' => $snapshot->correct_answer !== null
                    ? (string) $snapshot->correct_answer
                    : null,
                'scoring_rule' => $this->normalizeScoringRule($snapshot->scoring_rule),
                'weight' => (float) $snapshot->weight,
                'skill_slug' => trim((string) $snapshot->skill_slug),
            ];
        }

        return $this->resolveScoringFromVerifiedLiveItem($assessment, $snapshot);
    }

    /**
     * @return array{correct_answer: ?string, scoring_rule: ?array<string, float>, weight: float, skill_slug: string}
     *
     * @throws BaselineAssessmentException
     */
    private function resolveScoringFromVerifiedLiveItem(
        BaselineAssessment $assessment,
        BaselineQuestionSnapshot $snapshot
    ): array {
        /** @var BaselineAssessmentItem|null $item */
        $item = $snapshot->item;

        if (! $item) {
            throw BaselineAssessmentException::snapshotIncomplete(
                [(int) $snapshot->id],
                ['legacy_snapshot_without_live_item'],
            );
        }

        $frozenItemId = $this->questionSelection->snapshotItemId($snapshot);
        $frozenType = $this->questionSelection->snapshotItemType($snapshot);
        $frozenOptions = $this->questionSelection->snapshotOptions($snapshot);

        $mismatches = [];

        if ((string) $item->item_id !== $frozenItemId) {
            $mismatches[] = 'item_id_mismatch';
        }

        if ((int) $item->skill_id !== (int) $snapshot->skill_id) {
            $mismatches[] = 'skill_id_mismatch';
        }

        if ((string) $item->item_type !== $frozenType) {
            $mismatches[] = 'item_type_mismatch';
        }

        if ((string) $item->assessment_version !== (string) $assessment->assessment_version) {
            $mismatches[] = 'assessment_version_mismatch';
        }

        // Only verifiable when options were frozen. A pre-freeze snapshot has
        // no option list of its own, so there is nothing to compare.
        if ($frozenOptions !== []
            && $this->normalizeOptions($frozenOptions) !== $this->normalizeOptions(
                is_array($item->options) ? $item->options : []
            )) {
            $mismatches[] = 'options_mismatch';
        }

        if ($mismatches !== []) {
            throw BaselineAssessmentException::snapshotIncomplete(
                [(int) $snapshot->id],
                $mismatches,
            );
        }

        $slug = trim((string) $item->skill?->slug);

        if ($slug === '') {
            throw BaselineAssessmentException::snapshotIncomplete(
                [(int) $snapshot->id],
                ['missing_skill_slug'],
            );
        }

        // Diagnosis only: ids, never answers or learner data.
        Log::warning('Baseline snapshot is missing frozen scoring data; using verified live item.', [
            'assessment_id' => (int) $assessment->id,
            'snapshot_id' => (int) $snapshot->id,
            'baseline_assessment_item_id' => (int) $item->id,
        ]);

        return [
            'correct_answer' => $item->correct_answer !== null
                ? (string) $item->correct_answer
                : null,
            'scoring_rule' => $this->normalizeScoringRule($item->scoring_rule),
            'weight' => (float) $item->weight,
            'skill_slug' => $slug,
        ];
    }

    /**
     * @param  array<int, mixed>  $options
     * @return array<int, string>
     */
    private function normalizeOptions(array $options): array
    {
        return array_values(array_map(
            fn ($option) => (string) $option,
            $options,
        ));
    }

    /**
     * @return ?array<string, float>
     */
    private function normalizeScoringRule(mixed $rule): ?array
    {
        if (! is_array($rule) || $rule === []) {
            return null;
        }

        $normalized = [];

        foreach ($rule as $key => $value) {
            $normalized[(string) $key] = is_numeric($value) ? (float) $value : $value;
        }

        return $normalized;
    }

    /**
     * Mirror of the upstream schema/validators, applied BEFORE any HTTP call.
     *
     * Problems are field paths only — the payload carries learner answers and
     * grading keys, so neither is ever echoed into the error details or logs.
     *
     * @param  array{questions: array<int, array<string, mixed>>, responses: array<int, array<string, mixed>>}  $payload
     *
     * @throws BaselineAssessmentException
     */
    private function assertContract(array $payload): void
    {
        $questions = $payload['questions'];
        $responses = $payload['responses'];
        $problems = [];

        if ($questions === []) {
            $problems[] = 'questions';
        }

        if ($responses === []) {
            $problems[] = 'responses';
        }

        $knownQuestionIds = [];
        $slugBySkillId = [];

        foreach ($questions as $index => $question) {
            $path = "questions.{$index}";

            $questionId = $question['question_id'] ?? null;

            if (! is_int($questionId) || $questionId <= 0) {
                $problems[] = "{$path}.question_id";
            } elseif (isset($knownQuestionIds[$questionId])) {
                $problems[] = "{$path}.question_id_duplicate";
            } else {
                $knownQuestionIds[$questionId] = true;
            }

            $type = $question['question_type'] ?? null;

            if (! in_array($type, self::SUPPORTED_QUESTION_TYPES, true)) {
                $problems[] = "{$path}.question_type";
            }

            $options = $question['options'] ?? null;

            if (! is_array($options) || $options === []) {
                $problems[] = "{$path}.options";
                $options = [];
            } else {
                foreach ($options as $optionIndex => $option) {
                    if (! is_string($option) || trim($option) === '') {
                        $problems[] = "{$path}.options.{$optionIndex}";
                    }
                }
            }

            if ($type === self::TYPE_SINGLE_CHOICE) {
                $correct = $question['correct_answer'] ?? null;

                if (! is_string($correct) || trim($correct) === '') {
                    $problems[] = "{$path}.correct_answer";
                } elseif (! in_array($correct, $options, true)) {
                    $problems[] = "{$path}.correct_answer_not_in_options";
                }

                if (($question['scoring_rule'] ?? null) !== null) {
                    $problems[] = "{$path}.scoring_rule_must_be_null";
                }
            } elseif ($type === self::TYPE_SCALE) {
                if (($question['correct_answer'] ?? null) !== null) {
                    $problems[] = "{$path}.correct_answer_must_be_null";
                }

                $rule = $question['scoring_rule'] ?? null;

                if (! is_array($rule) || $rule === []) {
                    $problems[] = "{$path}.scoring_rule";
                } else {
                    $ruleKeys = array_map('strval', array_keys($rule));

                    if (array_diff($ruleKeys, $options) !== [] || array_diff($options, $ruleKeys) !== []) {
                        $problems[] = "{$path}.scoring_rule_keys_mismatch";
                    }

                    foreach ($rule as $key => $value) {
                        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 1) {
                            $problems[] = "{$path}.scoring_rule.{$key}";
                        }
                    }
                }
            }

            $weight = $question['weight'] ?? null;

            if (! is_numeric($weight) || (float) $weight <= 0) {
                $problems[] = "{$path}.weight";
            }

            $mappings = $question['skill_mappings'] ?? null;

            if (! is_array($mappings) || $mappings === []) {
                $problems[] = "{$path}.skill_mappings";

                continue;
            }

            $mappingWeightSum = 0.0;

            foreach ($mappings as $mappingIndex => $mapping) {
                $mappingPath = "{$path}.skill_mappings.{$mappingIndex}";

                $skillId = $mapping['skill_id'] ?? null;

                if (! is_int($skillId) || $skillId <= 0) {
                    $problems[] = "{$mappingPath}.skill_id";
                }

                $slug = $mapping['skill_slug'] ?? null;

                if (! is_string($slug) || trim($slug) === '') {
                    $problems[] = "{$mappingPath}.skill_slug";
                } elseif (is_int($skillId)) {
                    // Rule 13: one skill_id must not carry two different slugs
                    // inside the same snapshot.
                    if (isset($slugBySkillId[$skillId]) && $slugBySkillId[$skillId] !== $slug) {
                        $problems[] = "{$mappingPath}.skill_slug_conflict";
                    }

                    $slugBySkillId[$skillId] = $slug;
                }

                $mappingWeight = $mapping['weight'] ?? null;

                if (! is_numeric($mappingWeight) || (float) $mappingWeight <= 0) {
                    $problems[] = "{$mappingPath}.weight";
                } else {
                    $mappingWeightSum += (float) $mappingWeight;
                }
            }

            if (abs($mappingWeightSum - 1.0) > self::WEIGHT_EPSILON) {
                $problems[] = "{$path}.skill_mappings.weight_sum";
            }
        }

        $seenResponseIds = [];

        foreach ($responses as $index => $response) {
            $path = "responses.{$index}";
            $questionId = $response['question_id'] ?? null;

            if (! is_int($questionId) || $questionId <= 0) {
                $problems[] = "{$path}.question_id";
            } elseif (isset($seenResponseIds[$questionId])) {
                $problems[] = "{$path}.question_id_duplicate";
            } else {
                $seenResponseIds[$questionId] = true;
            }

            if (is_int($questionId) && ! isset($knownQuestionIds[$questionId])) {
                $problems[] = "{$path}.question_id_unknown";
            }

            if (! array_key_exists('answer', $response)) {
                $problems[] = "{$path}.answer";
            }
        }

        if ($problems !== []) {
            throw BaselineAssessmentException::invalidIntelligencePayload($problems);
        }
    }
}
