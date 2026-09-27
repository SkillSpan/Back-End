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
}
