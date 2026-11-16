<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ProjectException;
use App\Http\Controllers\Api\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Evaluation\DecideModerationCaseRequest;
use App\Http\Requests\Evaluation\StoreEvaluationExceptionRequest;
use App\Models\EvaluationModerationCase;
use App\Services\Evaluation\ModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ModerationController extends Controller
{
    use RespondsWithApi;

    public function __construct(private readonly ModerationService $moderation) {}

    public function index(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $status = (string) $request->query('status', EvaluationModerationCase::STATUS_OPEN);
            $cases = $this->moderation->queue($status);

            return $this->success(
                'Moderation queue retrieved successfully.',
                [
                    'cases' => $cases->map(fn (EvaluationModerationCase $case) => $this->moderation->casePayload($case))->values()->all(),
                ],
                200,
                $requestId,
            );
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    public function decide(DecideModerationCaseRequest $request, int $case): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = EvaluationModerationCase::query()->find($case);

        if (! $model) {
            return $this->notFound('MODERATION_CASE_NOT_FOUND', 'The requested moderation case does not exist.', ['case_id' => $case], $requestId);
        }

        try {
            $resolved = $this->moderation->decide(
                $model,
                $request->user(),
                $request->validated(),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'Moderation decision recorded successfully.',
                ['case' => $this->moderation->casePayload($resolved->load(['evaluation.project', 'evaluation.participant', 'evaluation.evaluator']))],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['case_id' => $model->id]);
        }
    }

    public function exceptions(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $type = (string) $request->query('type', 'all');
            $items = $this->moderation->exceptions($type);

            return $this->success(
                'Evaluation exceptions retrieved successfully.',
                [
                    'exceptions' => $items->map(fn ($exception) => $this->moderation->exceptionPayload($exception))->values()->all(),
                ],
                200,
                $requestId,
            );
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    public function storeException(StoreEvaluationExceptionRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $exception = $this->moderation->recordException(
                $request->user(),
                $request->validated(),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'Evaluation exception recorded successfully.',
                ['exception' => $this->moderation->exceptionPayload($exception)],
                201,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }
}
