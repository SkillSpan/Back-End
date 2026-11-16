<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ProjectException;
use App\Http\Controllers\Api\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Evaluation\StoreSelfReflectionRequest;
use App\Models\Project;
use App\Services\Evaluation\SelfReflectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class SelfReflectionController extends Controller
{
    use RespondsWithApi;

    public function __construct(private readonly SelfReflectionService $reflections) {}

    public function show(Request $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Project::query()->find($project);

        if (! $model) {
            return $this->notFound('PROJECT_NOT_FOUND', 'The requested project does not exist.', ['project_id' => $project], $requestId);
        }

        try {
            $reflection = $this->reflections->forProject($model, $request->user());

            return $this->success(
                'Self-reflection retrieved successfully.',
                ['self_reflection' => $reflection === null ? null : $this->reflections->payload($reflection)],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['project_id' => $model->id]);
        }
    }

    public function store(StoreSelfReflectionRequest $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Project::query()->find($project);

        if (! $model) {
            return $this->notFound('PROJECT_NOT_FOUND', 'The requested project does not exist.', ['project_id' => $project], $requestId);
        }

        try {
            $reflection = $this->reflections->save(
                $model,
                $request->user(),
                $request->validated(),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'Self-reflection saved successfully.',
                ['self_reflection' => $this->reflections->payload($reflection)],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['project_id' => $model->id]);
        }
    }

    public function submit(Request $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Project::query()->find($project);

        if (! $model) {
            return $this->notFound('PROJECT_NOT_FOUND', 'The requested project does not exist.', ['project_id' => $project], $requestId);
        }

        try {
            $reflection = $this->reflections->submit(
                $model,
                $request->user(),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'Self-reflection submitted successfully.',
                ['self_reflection' => $this->reflections->payload($reflection)],
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
