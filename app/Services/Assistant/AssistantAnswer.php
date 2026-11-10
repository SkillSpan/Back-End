<?php

namespace App\Services\Assistant;

use App\Models\AssistantInteraction;

final readonly class AssistantAnswer
{
    public const STATUS_ANSWERED = 'answered';

    public const STATUS_INSUFFICIENT_CONTEXT = 'insufficient_context';

    public function __construct(
        public AssistantInteraction $interaction,
        public string $reply,
        public ?string $providerUsed,
        public ?string $promptVersion,
        public ?string $answerStatus = null,
        public bool $grounded = false,
        public bool $contextGroundsIntent = true,
    ) {}

    public function wasAnsweredByAProvider(): bool
    {
        return $this->providerUsed !== null;
    }

    public function needsHumanHandoff(): bool
    {
        if (! $this->wasAnsweredByAProvider()) {
            return false;
        }

        return $this->answerStatus === self::STATUS_INSUFFICIENT_CONTEXT
            || ! $this->contextGroundsIntent;
    }
}
