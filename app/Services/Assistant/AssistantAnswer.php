<?php

namespace App\Services\Assistant;

use App\Models\AssistantInteraction;

/**
 * US-REC-01 — the outcome of one assistant turn.
 *
 * The audit row ({@see AssistantInteraction}) and the answer are separate on
 * purpose, because §12.5 requires them to be:
 *
 *   * The row is **persisted** and holds audit metadata only — no question, no
 *     reply (data minimisation, SRS v1.1 §9.5 / §12.5).
 *   * The reply is **returned to the caller and never stored**. Dropping it
 *     would break the UI; persisting it would breach the retention rule. So it
 *     has to travel outside the model, which is what this object is for.
 *
 * Passing the reply as an attribute on the model would be the tempting shortcut
 * and the wrong one: an unpersisted attribute on an Eloquent model looks
 * storable, and one careless `->save()` later it is in the database.
 *
 * `providerUsed` is null when every provider in the service's failover chain
 * failed. That is a **soft failure**: the reply is the service's fallback text,
 * the interaction is recorded as failed, and the learner still sees a message.
 */
final readonly class AssistantAnswer
{
    public function __construct(
        public AssistantInteraction $interaction,
        public string $reply,
        public ?string $providerUsed,
        public ?string $promptVersion,
    ) {}

    /**
     * True when a model actually answered.
     *
     * The service returns HTTP 200 with a fallback message when its whole
     * provider chain fails, so the status code cannot tell "answered" from
     * "nobody answered". This is the only reliable signal, and callers must
     * branch on it or an outage gets recorded as a success.
     */
    public function wasAnsweredByAProvider(): bool
    {
        return $this->providerUsed !== null;
    }
}
