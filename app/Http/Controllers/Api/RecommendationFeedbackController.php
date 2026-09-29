<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApplicationException;
use App\Http\Controllers\Controller;
use App\Models\Recommendation;
use App\Services\Projects\RecommendationFeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * US-MATCH-02 — POST /api/v1/recommendations/{recommendation}/feedback
 *
 * Records a learner's feedback on their OWN stored recommendation. Reuses the
 * existing feedback_events table and FeedbackEvent model (see
 * RecommendationFeedbackService) instead of a parallel store.
 */
class RecommendationFeedbackController extends Controller
{
    public function __construct(
        private readonly RecommendationFeedbackService $feedbackService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $requestId = (string) ($request->header('X-Request-ID') ?: Str::uuid());

        $recommendationId = (int) $request->route('recommendation');
        $recommendation = Recommendation::query()->find($recommendationId);

        if (! $recommendation) {
            return $this->errorResponse(
                'RECOMMENDATION_NOT_FOUND',
                'The requested recommendation does not exist.',
                404,
                $requestId,
                ['recommendation_id' => $recommendationId],
            );
        }

        $validator = validator($request->all(), [
            'event_type' => ['required', 'string', Rule::in(array_keys(RecommendationFeedbackService::EVENT_MAP))],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                'The request could not be processed.',
                422,
                $requestId,
                ['errors' => $validator->errors()->messages()],
            );
        }

        $data = $validator->validated();

        try {
            $event = $this->feedbackService->record(
                $recommendation,
                $request->user(),
                $data['event_type'],
                $data['reason'] ?? null,
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (ApplicationException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }

        return response()->json([
            'success' => true,
            'message' => 'Recommendation feedback recorded successfully.',
            'data' => [
                'id' => $event->id,
                'recommendation_id' => $event->recommendation_id,
                'event_type' => $event->event_type,
                'reason' => $event->reason,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ],
            'request_id' => $requestId,
        ], 201, ['X-Request-ID' => $requestId]);
    }

    private function errorResponse(
        string $code,
        string $message,
        int $status,
        string $requestId,
        array $details = [],
    ): JsonResponse {
        $payload = [
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ];

        if ($details !== []) {
            $payload['details'] = $details;
        }

        return response()->json($payload, $status, ['X-Request-ID' => $requestId]);
    }
}
