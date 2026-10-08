<?php

namespace App\Exceptions;

use Exception;

/**
 * Raised by the admin question bank for a request it must refuse.
 *
 * Carries the same `codeName` / `status` / `details` shape as
 * BaselineAssessmentException and ProjectException, so the panel's JSON
 * error envelope stays uniform across the admin API.
 */
class QuestionBankException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly string $codeName = 'QUESTION_BANK_ERROR',
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * A question cannot be deleted because one or more assessments already
     * reference it through a frozen snapshot.
     *
     * The snapshot foreign key cascades on delete, so removing the item
     * would erase the questions a learner was (or is being) assessed on.
     * Refused explicitly instead of silently corrupting those assessments.
     */
    public static function questionInUse(int $itemId, int $usageCount): self
    {
        return new self(
            'This question is used by one or more assessments and cannot be deleted.',
            409,
            'QUESTION_IN_USE',
            ['question_id' => $itemId, 'usage_count' => $usageCount],
        );
    }

    public static function questionNotFound(int $itemId): self
    {
        return new self(
            'The requested question does not exist.',
            404,
            'QUESTION_NOT_FOUND',
            ['question_id' => $itemId],
        );
    }
}
