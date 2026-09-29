<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BaselineAssessmentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveBaselineProgressRequest;
use App\Http\Requests\StartBaselineAssessmentRequest;
use App\Http\Requests\SubmitBaselineAssessmentRequest;
use App\Models\BaselineAssessment;
use App\Models\BaselineQuestionSnapshot;
use App\Models\CareerRole;
use App\Services\Baseline\BaselineAssessmentService;
use App\Services\Baseline\BaselineQuestionSelectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BaselineAssessmentController extends Controller
{
    public function __construct(
        private readonly BaselineAssessmentService $assessmentService,
        private readonly BaselineQuestionSelectionService $questionSelection,
    ) {}

    public function start(StartBaselineAssessmentRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);
        $user = $request->user();
        $studentProfile = $user->studentProfile;

        if (! $studentProfile) {
            return $this->errorResponse(
                'STUDENT_PROFILE_NOT_FOUND',
                'The authenticated learner does not have a student profile.',
                422,
                $requestId,
            );
        }

        try {
            $careerRole = CareerRole::findOrFail((int) $request->input('career_role_id'));

            $assessment = $this->assessmentService->start(
                $studentProfile,
                $careerRole,
                $requestId,
            );

            return response()->json([
                'success' => true,
                'message' => 'Baseline assessment started.',
                'data' => $this->transform($assessment),
            ], 201, ['X-Request-ID' => $requestId]);
        } catch (BaselineAssessmentException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->handleUnexpected($e, 'Baseline assessment could not be started.', $requestId, $user->id);
        }
    }

    public function show(Request $request, BaselineAssessment $assessment): JsonResponse
    {
        $requestId = $this->requestId($request);
        $user = $request->user();

        if (! $this->owns($assessment, $user->id)) {
            return $this->errorResponse(
                'ASSESSMENT_NOT_FOUND',
                'The requested baseline assessment does not exist.',
                404,
                $requestId,
            );
        }

        // Eager-load the frozen question set: retrieval must return the
        // exact questions selected at creation time, not the live bank.
        $assessment->loadMissing([
            'questionSnapshots.item',
            'careerRole:id,title,slug,version',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Baseline assessment retrieved successfully.',
            'data' => $this->transform($assessment),
        ], 200, ['X-Request-ID' => $requestId]);
    }

    public function progress(SaveBaselineProgressRequest $request, BaselineAssessment $assessment): JsonResponse
    {
        $requestId = $this->requestId($request);
        $user = $request->user();

        if (! $this->owns($assessment, $user->id)) {
            return $this->errorResponse(
                'ASSESSMENT_NOT_FOUND',
                'The requested baseline assessment does not exist.',
                404,
                $requestId,
            );
        }

        try {
            $assessment = $this->assessmentService->saveProgress(
                $assessment,
                $request->input('progress'),
                $request->input('responses'),
                $requestId,
            );

            $assessment->loadMissing([
                'questionSnapshots.item',
                'careerRole:id,title,slug,version',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Baseline assessment progress saved.',
                'data' => $this->transform($assessment),
            ], 200, ['X-Request-ID' => $requestId]);
        } catch (BaselineAssessmentException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->handleUnexpected($e, 'Baseline assessment progress could not be saved.', $requestId, $user->id);
        }
    }

    public function submit(SubmitBaselineAssessmentRequest $request, BaselineAssessment $assessment): JsonResponse
    {
        $requestId = $this->requestId($request);
        $user = $request->user();

        if (! $this->owns($assessment, $user->id)) {
            return $this->errorResponse(
                'ASSESSMENT_NOT_FOUND',
                'The requested baseline assessment does not exist.',
                404,
                $requestId,
            );
        }

        try {
            $assessment = $this->assessmentService->submit(
                $assessment,
                $request->input('responses', []),
                $requestId,
            );

            $assessment->loadMissing([
                'questionSnapshots.item',
                'careerRole:id,title,slug,version',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Baseline assessment completed successfully.',
                'data' => $this->transform($assessment),
            ], 200, ['X-Request-ID' => $requestId]);
        } catch (BaselineAssessmentException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->handleUnexpected($e, 'Baseline assessment could not be completed.', $requestId, $user->id);
        }
    }

    private function owns(BaselineAssessment $assessment, int $userId): bool
    {
        return $assessment->studentProfile !== null
            && $assessment->studentProfile->user_id === $userId;
    }

    /**
     * Serialize an assessment for API consumers.
     *
     * `questions` is built from the immutable snapshot and deliberately
     * omits `correct_answer` / `scoring_rule` — the backend never leaks
     * grading keys to the client. `responses` is included only once the
     * assessment is completed, so an in-progress attempt never echoes
     * partially-submitted answers back over the wire.
     */
    private function transform(BaselineAssessment $assessment): array
    {
        $questions = $assessment->relationLoaded('questionSnapshots')
            ? $assessment->questionSnapshots
                ->sortBy(fn ($snapshot) => strtolower($this->questionSelection->snapshotItemId($snapshot)))
                ->map(fn ($snapshot) => $this->transformQuestion($snapshot))
                ->values()
                ->all()
            : [];

        $careerRole = $assessment->relationLoaded('careerRole') && $assessment->careerRole
            ? [
                'id' => $assessment->careerRole->id,
                'title' => $assessment->careerRole->title,
                'slug' => $assessment->careerRole->slug,
                'version' => $assessment->careerRole->version,
            ]
            : null;

        return [
            'id' => $assessment->id,
            'assessment_type' => $assessment->assessment_type,
            'assessment_version' => $assessment->assessment_version,
            'career_role_id' => $assessment->career_role_id,
            'career_role' => $careerRole,
            'status' => $assessment->status,
            'question_count' => $assessment->question_count,
            'questions' => $questions,
            'skill_coverage' => $assessment->skill_coverage,
            'snapshot_metadata' => $assessment->snapshot_metadata,
            'progress' => $assessment->progress,
            'responses' => $assessment->isCompleted() ? $assessment->responses : null,
            'result' => $assessment->result,
            'normalized_skills' => $assessment->normalized_skills,
            'completed_at' => $assessment->completed_at?->toIso8601String(),
            'created_at' => $assessment->created_at?->toIso8601String(),
            'updated_at' => $assessment->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Serialize one frozen snapshot question for API consumers.
     *
     * Content comes from the snapshot (immutable after creation), never
     * from the live item bank. `correct_answer` / `scoring_rule` are
     * deliberately never included — grading keys stay server-side.
     *
     * `question_text` is null when no prompt was ever authored for the
     * item; we do not invent placeholder text.
     *
     * @param  BaselineQuestionSnapshot  $snapshot
     */
    private function transformQuestion($snapshot): array
    {
        return [
            'item_id' => $this->questionSelection->snapshotItemId($snapshot),
            'item_type' => $this->questionSelection->snapshotItemType($snapshot),
            'question_text' => $this->questionSelection->snapshotQuestionText($snapshot),
            'options' => $this->questionSelection->snapshotOptions($snapshot),
            'skill_id' => (int) $snapshot->skill_id,
            'skill_slug' => $snapshot->relationLoaded('skill') ? $snapshot->skill?->slug : null,
            'importance_weight' => (float) $snapshot->importance_weight,
            'is_critical' => (bool) $snapshot->is_critical,
        ];
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
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

    private function handleUnexpected(Throwable $e, string $message, string $requestId, int $userId): JsonResponse
    {
        Log::error($message, [
            'request_id' => $requestId,
            'user_id' => $userId,
            'failure_reason' => $e->getMessage(),
        ]);

        return $this->errorResponse(
            'BASELINE_ASSESSMENT_FAILED',
            $message,
            500,
            $requestId,
        );
    }
}
