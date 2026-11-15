<?php

namespace App\Services\Projects;

use App\Exceptions\ApplicationException;
use App\Models\FeedbackEvent;
use App\Models\Recommendation;
use App\Models\User;
use App\Traits\AuditsActions;

class RecommendationFeedbackService
{
    use AuditsActions;

    public const EVENT_MAP = [
        'save' => 'save',
        'hide' => 'hide',
        'decline' => 'reject',
    ];

    public function record(
        Recommendation $recommendation,
        User $learner,
        string $eventType,
        ?string $reason = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): FeedbackEvent {
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
