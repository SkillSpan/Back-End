<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ProjectException;
use App\Http\Controllers\Api\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Evaluation\StoreDisputeRequest;
use App\Models\Evaluation;
use App\Models\EvaluationDispute;
use App\Services\Evaluation\DisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class EvaluationDisputeController extends Controller
{
    use RespondsWithApi;

    public function __construct(private readonly DisputeService $disputes) {}

    public function store(StoreDisputeRequest $request, int $evaluation): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Evaluation::query()->find($evaluation);

        if (! $model) {
            return $this->notFound('EVALUATION_NOT_FOUND', 'The requested evaluation does not exist.', ['evaluation_id' => $evaluation], $requestId);
        }

        try {
            $dispute = $this->disputes->open(
                $model,
                $request->user(),
                $request->validated(),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'Dispute submitted successfully.',
                ['dispute' => $this->disputes->payload($dispute)],
                201,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['evaluation_id' => $model->id]);
        }
    }

    public function index(Request $request, int $evaluation): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Evaluation::query()->find($evaluation);

        if (! $model) {
            return $this->notFound('EVALUATION_NOT_FOUND', 'The requested evaluation does not exist.', ['evaluation_id' => $evaluation], $requestId);
        }

        try {
            $items = $this->disputes->listForEvaluation($model, $request->user());

            return $this->success(
                'Disputes retrieved successfully.',
                [
                    'disputes' => $items->map(fn (EvaluationDispute $dispute) => $this->disputes->payload($dispute))->values()->all(),
                ],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['evaluation_id' => $model->id]);
        }
    }

    public function mine(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $items = $this->disputes->listForStudent($request->user());

            return $this->success(
                'Disputes retrieved successfully.',
                [
                    'disputes' => $items->map(fn (EvaluationDispute $dispute) => array_merge(
                        $this->disputes->payload($dispute),
                        [
                            'project' => [
                                'id' => $dispute->evaluation?->project?->id,
                                'title' => $dispute->evaluation?->project?->title,
                            ],
                        ],
                    ))->values()->all(),
                ],
                200,
                $requestId,
            );
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }
}
