<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SkillMatchException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSkillMatchRequest;
use App\Services\Readiness\SkillMatchService;
use Illuminate\Http\JsonResponse;

class SkillMatchController extends Controller
{
    public function __construct(private readonly SkillMatchService $skillMatchService) {}

    public function store(StoreSkillMatchRequest $request): JsonResponse
    {
        try {
            $result = $this->skillMatchService->calculate($request->validated());

            return response()->json($result);
        } catch (SkillMatchException $e) {
            return response()->json([
                'detail' => [
                    'code' => $e->codeName,
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }
}
