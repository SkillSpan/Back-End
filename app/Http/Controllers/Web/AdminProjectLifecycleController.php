<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\ProjectManagementController;
use App\Http\Requests\ProjectReviewDecisionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminProjectLifecycleController extends ProjectManagementController
{
    public function submit(Request $request, int $project): JsonResponse
    {
        return parent::submit($request, $project);
    }

    public function approve(ProjectReviewDecisionRequest $request, int $project): JsonResponse
    {
        return parent::approve($request, $project);
    }

    public function requestChanges(ProjectReviewDecisionRequest $request, int $project): JsonResponse
    {
        return parent::requestChanges($request, $project);
    }

    public function reject(ProjectReviewDecisionRequest $request, int $project): JsonResponse
    {
        return parent::reject($request, $project);
    }

    public function open(Request $request, int $project): JsonResponse
    {
        return parent::open($request, $project);
    }
}
