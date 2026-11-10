<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\QuestionBankException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestionRequest;
use App\Http\Requests\UpdateQuestionRequest;
use App\Models\BaselineAssessmentItem;
use App\Services\Assessment\QuestionBankService;
use App\Support\SafeLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AdminQuestionController extends Controller
{
    public function __construct(
        private readonly QuestionBankService $service,
    ) {}

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
            'failure_reason' => SafeLog::reason($e),
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
