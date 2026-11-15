<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Talent\TalentEngagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LearnerMessageController extends Controller
{
    public function __construct(private readonly TalentEngagementService $engagement) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);
        $perPage = $perPage < 1 || $perPage > 50 ? 20 : $perPage;

        $page = $this->engagement->messagesFor($request->user(), $perPage);

        return response()->json([
            'success' => true,
            'message' => 'Messages retrieved successfully.',
            'data' => collect($page->items())->map(fn ($message) => [
                'id' => $message->id,
                'message' => $message->message,
                'status' => $message->status,
                'from' => [
                    'organization_id' => $message->organization_id,
                    'organization_name' => $message->organization?->name,
                    'sender_name' => $message->requester?->name,
                ],
                'created_at' => $message->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
