<?php

namespace App\Exceptions;

use Exception;

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
