<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ProjectException;
use App\Http\Controllers\Api\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Evaluation\DeactivateRubricVersionRequest;
use App\Http\Requests\Evaluation\PublishRubricVersionRequest;
use App\Http\Requests\Evaluation\StoreRubricRequest;
use App\Http\Requests\Evaluation\UpdateRubricVersionRequest;
use App\Models\Rubric;
use App\Services\Evaluation\RubricService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class RubricController extends Controller
{
    use RespondsWithApi;

    public function __construct(private readonly RubricService $rubrics) {}

    public function index(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        return $this->success(
            'Rubrics retrieved successfully.',
            ['rubrics' => $this->rubrics->listFamilies()],
            200,
            $requestId,
        );
    }

    public function store(StoreRubricRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $rubric = $this->rubrics->create(
                $request->user(),
                $request->validated(),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'Rubric draft created successfully.',
                ['rubric' => $this->rubrics->payload($rubric->load('dimensions.skills'))],
                201,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id]);
        }
    }

    public function storeVersion(Request $request, int $rubric): JsonResponse
    {
        $requestId = $this->requestId($request);

        $family = Rubric::query()->find($rubric);

        if (! $family) {
            return $this->notFound('RUBRIC_NOT_FOUND', 'The requested rubric does not exist.', ['rubric_id' => $rubric], $requestId);
        }

        try {
            $version = $this->rubrics->createVersion(
                $request->user(),
                $family,
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'A new rubric version draft was created.',
                ['rubric' => $this->rubrics->payload($version->load('dimensions.skills'))],
                201,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id]);
        }
    }

    public function update(UpdateRubricVersionRequest $request, int $rubric): JsonResponse
    {
        $requestId = $this->requestId($request);

        $version = Rubric::query()->find($rubric);

        if (! $version) {
            return $this->notFound('RUBRIC_NOT_FOUND', 'The requested rubric version does not exist.', ['rubric_version_id' => $rubric], $requestId);
        }

        try {
            $updated = $this->rubrics->updateDraft(
                $request->user(),
                $version,
                $request->validated(),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'Rubric draft updated successfully.',
                ['rubric' => $this->rubrics->payload($updated->load('dimensions.skills'))],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id]);
        }
    }

    public function publish(PublishRubricVersionRequest $request, int $rubric): JsonResponse
    {
        $requestId = $this->requestId($request);

        $version = Rubric::query()->find($rubric);

        if (! $version) {
            return $this->notFound('RUBRIC_NOT_FOUND', 'The requested rubric version does not exist.', ['rubric_version_id' => $rubric], $requestId);
        }

        try {
            $published = $this->rubrics->publish(
                $request->user(),
                $version,
                (string) $request->validated()['reason'],
                $request->validated()['effective_date'] ?? null,
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'Rubric version published successfully.',
                ['rubric' => $this->rubrics->payload($published->load('dimensions.skills'))],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id]);
        }
    }

    public function deactivate(DeactivateRubricVersionRequest $request, int $rubric): JsonResponse
    {
        $requestId = $this->requestId($request);

        $version = Rubric::query()->find($rubric);

        if (! $version) {
            return $this->notFound('RUBRIC_NOT_FOUND', 'The requested rubric version does not exist.', ['rubric_version_id' => $rubric], $requestId);
        }

        try {
            $deactivated = $this->rubrics->deactivate(
                $request->user(),
                $version,
                (string) $request->validated()['reason'],
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'Rubric version deactivated successfully.',
                ['rubric' => $this->rubrics->payload($deactivated->load('dimensions.skills'))],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['user_id' => $request->user()->id]);
        }
    }
}
