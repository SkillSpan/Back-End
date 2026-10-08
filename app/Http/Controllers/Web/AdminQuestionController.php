<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\QuestionBankException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestionRequest;
use App\Http\Requests\UpdateQuestionRequest;
use App\Models\BaselineAssessmentItem;
use App\Services\Assessment\QuestionBankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Session-authenticated endpoints behind the admin "Questions" page.
 *
 * The panel runs on a normal web session (a cookie), not a bearer token, so
 * these endpoints live under `/admin/api/questions/*` next to the projects
 * and organizations panel endpoints rather than under `auth:sanctum`. The
 * route group carries `auth` + `account.active` + `admin`, exactly like the
 * rest of the admin panel — no middleware is weakened for this page.
 *
 * All reads and writes go through QuestionBankService, which works on the
 * EXISTING `baseline_assessment_items` table; this class only shapes the
 * HTTP request/response.
 */
class AdminQuestionController extends Controller
{
    public function __construct(
        private readonly QuestionBankService $service,
    ) {}

    /**
     * GET /admin/api/questions
     *
     * The questions for the current filters (specialization / career role /
     * skill), scoped to the active assessment version and paginated.
     */
    public function index(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $perPage = max(1, min((int) $request->query('per_page', 15), 100));

        $questions = $this->service->paginate([
            'specialization_id' => $request->query('specialization_id'),
            'career_role_id' => $request->query('career_role_id'),
            'skill_id' => $request->query('skill_id'),
            'q' => $request->query('q'),
        ], $perPage);

        return response()->json([
            'success' => true,
            'message' => 'Questions retrieved successfully.',
            'data' => [
                'data' => collect($questions->items())
                    ->map(fn (BaselineAssessmentItem $item) => $this->transform($item))
                    ->values()
                    ->all(),
                'current_page' => $questions->currentPage(),
                'per_page' => $questions->perPage(),
                'total' => $questions->total(),
                'last_page' => $questions->lastPage(),
                'from' => $questions->firstItem(),
                'to' => $questions->lastItem(),
            ],
            'assessment_version' => $this->service->activeVersion(),
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * GET /admin/api/questions/specializations
     *
     * First dropdown — every active specialization.
     */
    public function specializations(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        return response()->json([
            'success' => true,
            'message' => 'Specializations retrieved successfully.',
            'data' => $this->service->specializations(),
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * GET /admin/api/questions/career-roles?specialization_id={id}
     *
     * Second dropdown — only the career roles linked to the specialization.
     */
    public function careerRoles(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $validated = $request->validate([
            'specialization_id' => ['required', 'integer', 'exists:specializations,id'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Career roles retrieved successfully.',
            'data' => $this->service->careerRoles((int) $validated['specialization_id']),
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * GET /admin/api/questions/skills?career_role_id={id}
     *
     * Third dropdown — only the skills required by the career role.
     */
    public function skills(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $validated = $request->validate([
            'career_role_id' => ['required', 'integer', 'exists:career_roles,id'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Skills retrieved successfully.',
            'data' => $this->service->skills((int) $validated['career_role_id']),
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * GET /admin/api/questions/skill-context?skill_id={id}
     *
     * Reverse lookup used by the edit form: given a question's skill, which
     * specialization / career role does it sit under? Lets the edit form
     * pre-select the chain even when the list was not filtered.
     */
    public function skillContext(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $validated = $request->validate([
            'skill_id' => ['required', 'integer', 'exists:skills,id'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Skill context retrieved successfully.',
            'data' => $this->service->contextForSkill((int) $validated['skill_id']),
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * POST /admin/api/questions
     */
    public function store(StoreQuestionRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $item = $this->service->create($request->validated());

            return $this->questionResponse($item, 'Question created successfully.', 201, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    /**
     * PATCH /admin/api/questions/{question}
     */
    public function update(UpdateQuestionRequest $request, BaselineAssessmentItem $question): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $item = $this->service->update($question, $request->validated());

            return $this->questionResponse($item, 'Question updated successfully.', 200, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    /**
     * DELETE /admin/api/questions/{question}
     *
     * Refused with a 409 when the question is already part of an assessment
     * (its frozen snapshot would otherwise be cascaded away).
     */
    public function destroy(Request $request, BaselineAssessmentItem $question): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $this->service->delete($question);

            return response()->json([
                'success' => true,
                'message' => 'Question deleted successfully.',
                'data' => ['id' => (int) $question->id],
                'request_id' => $requestId,
            ], 200, ['X-Request-ID' => $requestId]);
        } catch (QuestionBankException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    // -----------------------------------------------------------------
    // Response helpers
    // -----------------------------------------------------------------

    private function questionResponse(
        BaselineAssessmentItem $item,
        string $message,
        int $status,
        string $requestId,
    ): JsonResponse {
        $item->loadMissing('skill:id,name,slug');

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $this->transform($item),
            'request_id' => $requestId,
        ], $status, ['X-Request-ID' => $requestId]);
    }

    /**
     * The admin representation of a question.
     *
     * `correct_answer` IS included: this is an administrator-only surface
     * and the grading key is what they are managing. The learner-facing
     * baseline API (BaselineAssessmentController) still omits it — the two
     * surfaces do not share a serializer.
     *
     * @return array<string, mixed>
     */
    private function transform(BaselineAssessmentItem $item): array
    {
        return [
            'id' => (int) $item->id,
            'item_id' => (string) $item->item_id,
            'item_type' => (string) $item->item_type,
            'question_text' => $item->question_text,
            'options' => is_array($item->options) ? array_values($item->options) : [],
            'correct_answer' => $item->correct_answer,
            'skill_id' => (int) $item->skill_id,
            'skill_name' => $item->skill?->name,
            'assessment_version' => (string) $item->assessment_version,
            'is_active' => (bool) $item->is_active,
            'usage_count' => (int) ($item->usage_count ?? 0),
            'weight' => (float) $item->weight,
        ];
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
    }

    private function unexpected(Throwable $e, string $requestId): JsonResponse
    {
        Log::error('Admin question request failed.', [
            'request_id' => $requestId,
            'failure_reason' => $e->getMessage(),
        ]);

        return $this->errorResponse(
            'QUESTION_REQUEST_FAILED',
            'The question request could not be completed.',
            500,
            $requestId,
        );
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
