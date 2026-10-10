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
 *
 * `answerStatus` is a **different** signal and the two must not be conflated:
 *
 *   * `providerUsed === null` → nobody answered at all (infrastructure).
 *   * `answerStatus === 'insufficient_context'` → a model answered, and said it
 *     does not have the information. The service reports this explicitly, and it
 *     is what the human-handoff offer is built on.
 *
 * `contextGroundsIntent` is the second, and in practice the **only working**,
 * input to the handoff decision — see {@see needsHumanHandoff()}.
 */
final readonly class AssistantAnswer
{
    /** The service answered from its documentation. */
    public const STATUS_ANSWERED = 'answered';

    /** The service replied, but had nothing to ground an answer in. */
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

    /**
     * True when the learner should be offered a human.
     *
     * Two independent routes, both requiring a provider to have actually
     * answered:
     *
     *  1. The service reported `insufficient_context` — the designed signal.
     *  2. The snapshot Laravel sent had nothing to ground *this question*
     *     in, so `contextGroundsIntent` is false.
     *
     * (2) is not a workaround for a bug in (1) — it is the only route that
     * fires. The service answers every call it receives, so (1) has never
     * occurred in production (0 rows, verified). Laravel knows which
     * sources it sent; that fact is what the offer is built on.
     * {@see AssistantContextBuilder::groundsIntent()}
     *
     * A total provider outage still returns false: the learner is reading
     * the service's own fallback text and the interaction is recorded as
     * failed. Offering "talk to support" there would hand a human a
     * question the assistant never actually considered.
     */
    public function needsHumanHandoff(): bool
    {
        if (! $this->wasAnsweredByAProvider()) {
            return false;
        }

        return $this->answerStatus === self::STATUS_INSUFFICIENT_CONTEXT
            || ! $this->contextGroundsIntent;
    }
}
