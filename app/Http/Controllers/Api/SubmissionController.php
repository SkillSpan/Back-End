<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ProjectException;
use App\Http\Controllers\Api\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreSubmissionRequest;
use App\Models\Project;
use App\Models\Submission;
use App\Services\Evaluation\FirstPassCheckService;
use App\Services\Projects\SubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SubmissionController extends Controller
{
    use RespondsWithApi;

    public function __construct(
        private readonly SubmissionService $submissions,
        private readonly FirstPassCheckService $firstPass,
    ) {}

    public function store(StoreSubmissionRequest $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Project::query()->find($project);

        if (! $model) {
            return $this->notFound('PROJECT_NOT_FOUND', 'The requested project does not exist.', ['project_id' => $project], $requestId);
        }

        try {
            $result = $this->submissions->create(
                $model,
                $request->user(),
                $request->validated(),
                $request->file('file'),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            $submission = $result['submission']->load(['contributor', 'firstPassCheck', 'milestone']);

            return $this->success(
                $result['replayed'] ? 'This submission was already received.' : 'Deliverable submitted successfully.',
                [
                    'submission' => $this->submissions->payload($submission),
                    'replayed' => $result['replayed'],
                ],
                $result['replayed'] ? 200 : 201,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['project_id' => $project]);
        }
    }

    public function index(Request $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Project::query()->find($project);

        if (! $model) {
            return $this->notFound('PROJECT_NOT_FOUND', 'The requested project does not exist.', ['project_id' => $project], $requestId);
        }

        try {
            $milestoneId = $request->query('milestone_id');
            $onlyMine = filter_var($request->query('mine', false), FILTER_VALIDATE_BOOL);

            $history = $this->submissions->history(
                $model,
                $request->user(),
                $milestoneId === null ? null : (int) $milestoneId,
                $onlyMine,
            );

            return $this->success(
                'Submission history retrieved successfully.',
                [
                    'submissions' => $history->map(fn (Submission $submission) => $this->submissions->payload($submission))->values()->all(),
                ],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['project_id' => $project]);
        }
    }

    public function show(Request $request, int $submission): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $model = $this->submissions->findForViewer($submission, $request->user());

            return $this->success(
                'Submission retrieved successfully.',
                ['submission' => $this->submissions->payload($model)],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['submission_id' => $submission]);
        }
    }

    public function firstPass(Request $request, int $submission): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $model = $this->submissions->findForViewer($submission, $request->user());

            return $this->success(
                'First-pass check retrieved successfully.',
                ['first_pass_check' => $this->firstPass->payload($model)],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['submission_id' => $submission]);
        }
    }

    public function file(Request $request, int $submission)
    {
        $requestId = $this->requestId($request);

        try {
            $model = $this->submissions->findForViewer($submission, $request->user());
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        }

        if ($model->file_path === null) {
            return $this->notFound('SUBMISSION_FILE_NOT_FOUND', 'This submission has no uploaded file.', ['submission_id' => $model->id], $requestId);
        }

        $disk = Storage::disk($this->submissions->disk());

        if (! $disk->exists((string) $model->file_path)) {
            return $this->notFound('SUBMISSION_FILE_UNAVAILABLE', 'The submitted file is no longer available on storage.', ['submission_id' => $model->id], $requestId);
        }

        return $disk->download(
            (string) $model->file_path,
            $model->original_filename ?: 'deliverable',
        );
    }
}
