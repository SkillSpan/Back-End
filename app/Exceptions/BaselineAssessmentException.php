<?php

namespace App\Exceptions;

use Exception;

class BaselineAssessmentException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly string $codeName = 'BASELINE_ASSESSMENT_ERROR',
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The requested career role has no active required skills configured,
     * so a question set cannot be built for it.
     */
    public static function careerRoleHasNoSkills(int $careerRoleId, array $details = []): self
    {
        return new self(
            'The selected career role has no required skills configured.',
            422,
            'CAREER_ROLE_NO_SKILLS',
            array_merge(['career_role_id' => $careerRoleId], $details),
        );
    }

    /**
     * At least one required skill could not be represented in the
     * assessment because the active question bank has no mapped items.
     * This is a server-side configuration error, surfaced explicitly
     * rather than silently producing an under-covered assessment.
     */
    public static function insufficientQuestionCoverage(array $uncoveredSkills): self
    {
        return new self(
            'Insufficient question bank coverage for one or more required skills.',
            422,
            'INSUFFICIENT_QUESTION_COVERAGE',
            ['uncovered_skills' => $uncoveredSkills],
        );
    }

    /**
     * The configured total question cap is smaller than the number of
     * required skills, so no assessment can cover every skill. This is a
     * contradictory configuration, not a data problem — reported
     * explicitly instead of silently dropping a skill's coverage.
     */
    public static function insufficientQuestionCapacity(
        int $requiredSkillCount,
        int $maxTotalQuestions
    ): self {
        return new self(
            'The configured maximum total questions is smaller than the number of required skills, so full skill coverage is impossible.',
            422,
            'INSUFFICIENT_QUESTION_CAPACITY',
            [
                'required_skill_count' => $requiredSkillCount,
                'max_total_questions' => $maxTotalQuestions,
            ],
        );
    }

    /**
     * A question selected for the assessment is unusable: it has no
     * authored prompt, or its content does not satisfy its own item type
     * (e.g. a `single_choice` question with no options).
     *
     * This is a question-bank data error. The assessment is refused
     * outright rather than silently shipping a question the learner
     * cannot read or answer — no placeholder text is ever invented.
     */
    public static function invalidQuestionContent(array $invalidQuestions): self
    {
        return new self(
            'One or more selected questions have invalid content and cannot be used in an assessment.',
            422,
            'INVALID_QUESTION_CONTENT',
            ['invalid_questions' => $invalidQuestions],
        );
    }

    /**
     * A frozen snapshot row cannot be turned into a trustworthy Intelligence
     * request: it has no numeric assessment item id, no frozen scoring data,
     * and no verified fallback to the live item.
     *
     * Fails closed — scoring a learner from a snapshot we cannot prove is
     * the one they were shown would produce an unverifiable result.
     *
     * @param  array<int, int|string>  $snapshotIds
     * @param  array<int, string>  $reasons
     */
    public static function snapshotIncomplete(array $snapshotIds, array $reasons = []): self
    {
        return new self(
            'The baseline assessment snapshot is incomplete and cannot be scored.',
            422,
            'ASSESSMENT_SNAPSHOT_INCOMPLETE',
            [
                'snapshot_ids' => array_values($snapshotIds),
                'reasons' => array_values($reasons),
            ],
        );
    }

    /**
     * A selected question's type is not part of the Intelligence baseline
     * contract (`single_choice` | `scale`). Sending it would only produce an
     * opaque upstream 422, so it is refused before any HTTP call.
     *
     * @param  array<int, array<string, mixed>>  $questions
     */
    public static function unsupportedQuestionType(array $questions): self
    {
        return new self(
            'One or more selected questions use a question type the intelligence baseline contract does not support.',
            422,
            'BASELINE_QUESTION_TYPE_UNSUPPORTED',
            ['unsupported_questions' => $questions],
        );
    }

    /**
     * The payload built from the snapshot does not satisfy the Intelligence
     * baseline schema. The request is never sent.
     *
     * @param  array<int, string>  $problems  Field paths only — never answers.
     */
    public static function invalidIntelligencePayload(array $problems): self
    {
        return new self(
            'The baseline assessment could not be prepared for the intelligence service.',
            422,
            'BASELINE_INTELLIGENCE_PAYLOAD_INVALID',
            ['problems' => array_values($problems)],
        );
    }
}
