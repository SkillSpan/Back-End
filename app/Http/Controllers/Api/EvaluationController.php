<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ProjectException;
use App\Http\Controllers\Api\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Evaluation\ConflictDeclarationRequest;
use App\Http\Requests\Evaluation\CorrectionRequestRequest;
use App\Http\Requests\Evaluation\StoreEvaluationRequest;
use App\Http\Requests\Evaluation\UpdateEvaluationRequest;
use App\Models\Evaluation;
use App\Models\Project;
use App\Services\Evaluation\EvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class EvaluationController extends Controller
{
    use RespondsWithApi;

    public function __construct(private readonly EvaluationService $evaluations) {}

    public function tasks(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $tasks = $this->evaluations->tasksFor($request->user());

            return $this->success(
                'Evaluation tasks retrieved successfully.',
                [
                    'tasks' => $tasks->map(fn ($task) => $this->evaluations->taskPayload($task))->values()->all(),
                ],
                200,
                $requestId,
            );
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    public function store(StoreEvaluationRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $evaluation = $this->evaluations->createDraft(
                $request->user(),
                $request->validated(),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'Evaluation draft created successfully.',
                ['evaluation' => $this->evaluations->payload($evaluation, true)],
                201,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    public function show(Request $request, int $evaluation): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Evaluation::query()->find($evaluation);

        if (! $model) {
            return $this->notFound('EVALUATION_NOT_FOUND', 'The requested evaluation does not exist.', ['evaluation_id' => $evaluation], $requestId);
        }

        $user = $request->user();
        $isEvaluator = (int) $model->evaluator_id === (int) $user->id;
        $isParticipant = (int) $model->participant_id === (int) $user->id;
        $isAdmin = $user->hasRole('admin');

        if (! $isEvaluator && ! $isParticipant && ! $isAdmin) {
            return $this->failure('EVALUATION_FORBIDDEN', 'You are not allowed to view this evaluation.', 403, $requestId);
        }

        if ($isParticipant && ! $isAdmin && ! $model->isFinalized()) {
            return $this->failure('EVALUATION_NOT_PUBLISHED', 'This evaluation has not been finalized yet.', 403, $requestId, [
                'evaluation_id' => $model->id,
                'status' => $model->status,
            ]);
        }

        return $this->success(
            'Evaluation retrieved successfully.',
            ['evaluation' => $this->evaluations->payload($model, $isEvaluator || $isAdmin)],
            200,
            $requestId,
        );
    }

    public function update(UpdateEvaluationRequest $request, int $evaluation): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Evaluation::query()->find($evaluation);

        if (! $model) {
            return $this->notFound('EVALUATION_NOT_FOUND', 'The requested evaluation does not exist.', ['evaluation_id' => $evaluation], $requestId);
        }

        try {
            $updated = $this->evaluations->updateDraft(
                $model,
                $request->user(),
                $request->validated(),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'Evaluation draft saved successfully.',
                ['evaluation' => $this->evaluations->payload($updated, true)],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['evaluation_id' => $model->id]);
        }
    }

    public function submit(Request $request, int $evaluation): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Evaluation::query()->find($evaluation);

        if (! $model) {
            return $this->notFound('EVALUATION_NOT_FOUND', 'The requested evaluation does not exist.', ['evaluation_id' => $evaluation], $requestId);
        }

        try {
            $submitted = $this->evaluations->submit(
                $model,
                $request->user(),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                $submitted->status === Evaluation::STATUS_MODERATION
                    ? 'Evaluation submitted and queued for moderation.'
                    : 'Evaluation submitted and finalized successfully.',
                ['evaluation' => $this->evaluations->payload($submitted, true)],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['evaluation_id' => $model->id]);
        }
    }

    public function conflict(ConflictDeclarationRequest $request, int $evaluation): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Evaluation::query()->find($evaluation);

        if (! $model) {
            return $this->notFound('EVALUATION_NOT_FOUND', 'The requested evaluation does not exist.', ['evaluation_id' => $evaluation], $requestId);
        }

        try {
            $updated = $this->evaluations->declareConflict(
                $model,
                $request->user(),
                (string) $request->input('reason'),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'Conflict of interest declared. The evaluation was sent to moderation.',
                ['evaluation' => $this->evaluations->payload($updated, true)],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['evaluation_id' => $model->id]);
        }
    }

    public function correction(CorrectionRequestRequest $request, int $evaluation): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Evaluation::query()->find($evaluation);

        if (! $model) {
            return $this->notFound('EVALUATION_NOT_FOUND', 'The requested evaluation does not exist.', ['evaluation_id' => $evaluation], $requestId);
        }

        try {
            $correction = $this->evaluations->requestCorrection(
                $model,
                $request->user(),
                (string) $request->input('reason'),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'Correction request submitted successfully.',
                [
                    'correction_request' => [
                        'id' => $correction->id,
                        'evaluation_id' => $correction->evaluation_id,
                        'status' => $correction->status,
                        'reason' => $correction->reason,
                        'created_at' => $correction->created_at?->toIso8601String(),
                    ],
                ],
                201,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['evaluation_id' => $model->id]);
        }
    }

    public function results(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $results = $this->evaluations->studentResults($request->user());

            return $this->success(
                'Finalized evaluations retrieved successfully.',
                [
                    'results' => $results->map(fn (Evaluation $evaluation) => $this->evaluations->resultPayload($evaluation))->values()->all(),
                ],
                200,
                $requestId,
            );
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId);
        }
    }

    public function project(Request $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Project::query()->find($project);

        if (! $model) {
            return $this->notFound('PROJECT_NOT_FOUND', 'The requested project does not exist.', ['project_id' => $project], $requestId);
        }

        try {
            return $this->success(
                'Project evaluations retrieved successfully.',
                ['evaluations' => $this->evaluations->projectEvaluations($model, $request->user())],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['project_id' => $model->id]);
        }
    }
}
