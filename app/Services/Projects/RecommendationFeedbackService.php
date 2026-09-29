<?php

namespace App\Services\Projects;

use App\Exceptions\ApplicationException;
use App\Models\FeedbackEvent;
use App\Models\Recommendation;
use App\Models\User;
use App\Traits\AuditsActions;

/**
 * US-MATCH-02 — recommendation feedback (save / hide / decline).
 *
 * Reuses the EXISTING feedback mechanism rather than introducing a parallel
 * one: rows still land in `feedback_events` through the existing `FeedbackEvent`
 * model, and the existing `Recommendation::feedbackEvents()` relation still
 * reads them. Nothing new was added to the schema for save or decline; `hide`
 * was appended to the existing event_type enum by
 * 2026_09_29_000005_add_hide_to_feedback_events_enum.
 *
 * MAPPING
 * -------
 * `decline` maps onto the existing `reject` event. US-MATCH-02 lists decline
 * alongside the events that already exist and forbids creating parallel
 * concepts where one is present, so a learner "declining" a recommendation
 * records a `reject` event with the learner's reason.
 *
 * SUPPRESSION
 * -----------
 * US-MATCH-02 mentions suppressing hidden/declined recommendations "for a
 * configured period". No such configuration exists anywhere in config/ or the
 * seeders, so NO suppression window is applied and NO duration is invented —
 * only the event is recorded. The gap is documented in the final report.
 */
class RecommendationFeedbackService
{
    use AuditsActions;

    /** Learner-facing event names → stored feedback_events.event_type. */
    public const EVENT_MAP = [
        'save' => 'save',
        'hide' => 'hide',
        'decline' => 'reject',
    ];

    /**
     * Record feedback for a recommendation the learner owns.
     *
     * @throws ApplicationException when the recommendation is not the learner's,
     *                              or the event is not supported.
     */
    public function record(
        Recommendation $recommendation,
        User $learner,
        string $eventType,
        ?string $reason = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): FeedbackEvent {
        // `recommendations` is keyed on user_id (there is no student_profile_id
        // column), so ownership is a direct comparison. Same scoping the
        // RecommendationController index uses.
        if ((int) $recommendation->user_id !== (int) $learner->id) {
            throw new ApplicationException(
                'This recommendation does not belong to you.',
                403,
                'RECOMMENDATION_NOT_OWNED',
                ['recommendation_id' => $recommendation->id],
            );
        }

        if (! array_key_exists($eventType, self::EVENT_MAP)) {
            throw new ApplicationException(
                'That feedback event is not supported.',
                422,
                'RECOMMENDATION_FEEDBACK_UNSUPPORTED',
                [
                    'event_type' => $eventType,
                    'supported' => array_keys(self::EVENT_MAP),
                ],
            );
        }

        $storedType = self::EVENT_MAP[$eventType];

        $event = FeedbackEvent::create([
            'recommendation_id' => $recommendation->id,
            'user_id' => $learner->id,
            'event_type' => $storedType,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);

        $this->audit(
            $learner->id,
            'recommendation.feedback.'.$storedType,
            'recommendation',
            $recommendation->id,
            null,
            [
                'event_id' => $event->id,
                'event_type' => $storedType,
                'requested_event_type' => $eventType,
                'reason' => $reason,
            ],
            'recommendation_feedback',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $event;
    }

    /**
     * The learner's own feedback events for one recommendation, newest first.
     */
    public function forRecommendation(Recommendation $recommendation, User $learner)
    {
        if ((int) $recommendation->user_id !== (int) $learner->id) {
            throw new ApplicationException(
                'This recommendation does not belong to you.',
                403,
                'RECOMMENDATION_NOT_OWNED',
                ['recommendation_id' => $recommendation->id],
            );
        }

        return FeedbackEvent::where('recommendation_id', $recommendation->id)
            ->where('user_id', $learner->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();
    }
}
