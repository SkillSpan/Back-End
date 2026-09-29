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
}
