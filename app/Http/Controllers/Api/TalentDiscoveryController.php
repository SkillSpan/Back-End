<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TalentCardResource;
use App\Http\Resources\TalentProfileResource;
use App\Services\Talent\TalentDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TalentDiscoveryController extends Controller
{
    public function __construct(private readonly TalentDiscoveryService $talent) {}

    public function index(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        $page = $this->talent->paginate($request->user(), $this->validatedFilters($request));

        return response()->json([
            'success' => true,
            'message' => 'Discoverable talent retrieved successfully.',
            'data' => TalentCardResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'request_id' => $requestId,
        ]);
    }

    public function show(Request $request, int $student): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $profile = $this->talent->profile($request->user(), $student);
        } catch (NotFoundHttpException) {
            return response()->json([
                'success' => false,
                'message' => 'No discoverable student with that id.',
                'request_id' => $requestId,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Talent profile retrieved successfully.',
            'data' => (new TalentProfileResource($profile))->resolve($request),
            'request_id' => $requestId,
        ]);
    }

    public function filters(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Talent discovery filters retrieved successfully.',
            'data' => $this->talent->filters($request->user()),
            'request_id' => $this->requestId($request),
        ]);
    }

    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'career_role' => ['nullable', 'string', 'max:255'],
            'min_readiness' => ['nullable', 'numeric', 'between:0,100'],
            'sort' => ['nullable', 'string', Rule::in(TalentDiscoveryService::SORTS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,'.TalentDiscoveryService::MAX_PER_PAGE],
        ]);
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
    }
}
