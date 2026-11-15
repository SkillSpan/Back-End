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

    public static function careerRoleHasNoSkills(int $careerRoleId, array $details = []): self
    {
        return new self(
            'The selected career role has no required skills configured.',
            422,
            'CAREER_ROLE_NO_SKILLS',
            array_merge(['career_role_id' => $careerRoleId], $details),
        );
    }

    public static function insufficientQuestionCoverage(array $uncoveredSkills): self
    {
        return new self(
            'Insufficient question bank coverage for one or more required skills.',
            422,
            'INSUFFICIENT_QUESTION_COVERAGE',
            ['uncovered_skills' => $uncoveredSkills],
        );
    }

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

    public static function invalidQuestionContent(array $invalidQuestions): self
    {
        return new self(
            'One or more selected questions have invalid content and cannot be used in an assessment.',
            422,
            'INVALID_QUESTION_CONTENT',
            ['invalid_questions' => $invalidQuestions],
        );
    }

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

    public static function unsupportedQuestionType(array $questions): self
    {
        return new self(
            'One or more selected questions use a question type the intelligence baseline contract does not support.',
            422,
            'BASELINE_QUESTION_TYPE_UNSUPPORTED',
            ['unsupported_questions' => $questions],
        );
    }

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
