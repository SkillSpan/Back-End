<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ProjectException;
use App\Http\Controllers\Api\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\DecideExtensionRequestRequest;
use App\Http\Requests\Projects\ReorderMilestonesRequest;
use App\Http\Requests\Projects\StoreExtensionRequestRequest;
use App\Http\Requests\Projects\StoreMilestoneRequest;
use App\Http\Requests\Projects\UpdateMilestoneRequest;
use App\Models\MilestoneExtensionRequest;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Services\Projects\ExtensionRequestService;
use App\Services\Projects\MilestoneService;
use App\Services\Projects\ProjectWorkspaceAccess;
use App\Services\Projects\ProjectWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ProjectWorkspaceController extends Controller
{
    use RespondsWithApi;

    public function __construct(
        private readonly ProjectWorkspaceService $workspace,
        private readonly ProjectWorkspaceAccess $access,
        private readonly MilestoneService $milestones,
        private readonly ExtensionRequestService $extensions,
    ) {}

    public function workspace(Request $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Project::query()->with(['owner', 'organization'])->find($project);

        if (! $model) {
            return $this->notFound('PROJECT_NOT_FOUND', 'The requested project does not exist.', ['project_id' => $project], $requestId);
        }

        try {
            return $this->success(
                'Project workspace retrieved successfully.',
                $this->workspace->payload($model, $request->user()),
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['project_id' => $project]);
        }
    }

    public function milestones(Request $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Project::query()->find($project);

        if (! $model) {
            return $this->notFound('PROJECT_NOT_FOUND', 'The requested project does not exist.', ['project_id' => $project], $requestId);
        }

        try {
            $this->access->assertMemberOrManager($model, $request->user());

            return $this->success(
                'Milestones retrieved successfully.',
                ['milestones' => $this->milestones->payloadCollection($this->milestones->listForProject($model))],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['project_id' => $project]);
        }
    }

    public function storeMilestone(StoreMilestoneRequest $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Project::query()->find($project);

        if (! $model) {
            return $this->notFound('PROJECT_NOT_FOUND', 'The requested project does not exist.', ['project_id' => $project], $requestId);
        }

        try {
            $milestone = $this->milestones->create(
                $model,
                $request->user(),
                $request->validated(),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'Milestone created successfully.',
                ['milestone' => $this->milestones->payload($milestone)],
                201,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['project_id' => $project]);
        }
    }

    public function updateMilestone(UpdateMilestoneRequest $request, int $milestone): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = ProjectMilestone::query()->with('project')->find($milestone);

        if (! $model || ! $model->project) {
            return $this->notFound('MILESTONE_NOT_FOUND', 'The requested milestone does not exist.', ['milestone_id' => $milestone], $requestId);
        }

        try {
            $updated = $this->milestones->update(
                $model->project,
                $model,
                $request->user(),
                $request->validated(),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'Milestone updated successfully.',
                ['milestone' => $this->milestones->payload($updated)],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['milestone_id' => $milestone]);
        }
    }

    public function reorderMilestones(ReorderMilestonesRequest $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Project::query()->find($project);

        if (! $model) {
            return $this->notFound('PROJECT_NOT_FOUND', 'The requested project does not exist.', ['project_id' => $project], $requestId);
        }

        try {
            $ordered = $this->milestones->reorder(
                $model,
                $request->user(),
                (array) $request->validated()['milestone_ids'],
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'Milestones reordered successfully.',
                ['milestones' => $this->milestones->payloadCollection($ordered)],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['project_id' => $project]);
        }
    }

    public function storeExtension(StoreExtensionRequestRequest $request, int $milestone): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = ProjectMilestone::query()->with('project')->find($milestone);

        if (! $model || ! $model->project) {
            return $this->notFound('MILESTONE_NOT_FOUND', 'The requested milestone does not exist.', ['milestone_id' => $milestone], $requestId);
        }

        try {
            $extension = $this->extensions->request(
                $model->project,
                $model,
                $request->user(),
                $request->validated(),
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'Extension request submitted successfully.',
                ['extension_request' => $this->extensions->payload($extension->load(['milestone', 'requestedBy']))],
                201,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['milestone_id' => $milestone]);
        }
    }

    public function indexExtensions(Request $request, int $project): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = Project::query()->find($project);

        if (! $model) {
            return $this->notFound('PROJECT_NOT_FOUND', 'The requested project does not exist.', ['project_id' => $project], $requestId);
        }

        try {
            $this->access->assertManager($model, $request->user());

            $items = $this->extensions->listForProject($model)
                ->map(fn (MilestoneExtensionRequest $extension) => $this->extensions->payload($extension))
                ->values()
                ->all();

            return $this->success(
                'Extension requests retrieved successfully.',
                ['extension_requests' => $items],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['project_id' => $project]);
        }
    }

    public function decideExtension(DecideExtensionRequestRequest $request, int $extension): JsonResponse
    {
        $requestId = $this->requestId($request);
        $model = MilestoneExtensionRequest::query()->with(['milestone.project', 'requestedBy'])->find($extension);

        if (! $model || ! $model->milestone || ! $model->milestone->project) {
            return $this->notFound('EXTENSION_NOT_FOUND', 'The requested extension request does not exist.', ['extension_request_id' => $extension], $requestId);
        }

        try {
            $decided = $this->extensions->decide(
                $model->milestone->project,
                $model,
                $request->user(),
                (string) $request->validated()['decision'],
                $request->validated()['new_due_date'] ?? null,
                $request->validated()['reason'] ?? null,
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );

            return $this->success(
                'Extension request decided successfully.',
                ['extension_request' => $this->extensions->payload($decided->load(['milestone', 'requestedBy']))],
                200,
                $requestId,
            );
        } catch (ProjectException $e) {
            return $this->failureFrom($e, $requestId);
        } catch (Throwable $e) {
            return $this->unexpected($e, $requestId, ['extension_request_id' => $extension]);
        }
    }
}
