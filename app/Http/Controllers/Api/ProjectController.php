<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $requestId = (string) ($request->header('X-Request-ID') ?: Str::uuid());

        $user = $request->user();
        $studentProfile = $user->studentProfile;

        if (! $studentProfile) {
            return $this->errorResponse(
                'STUDENT_PROFILE_NOT_FOUND',
                'The authenticated learner does not have a student profile.',
                422,
                $requestId,
            );
        }

        $organizationId = DB::table('organization_members')
            ->where('user_id', $user->id)
            ->value('organization_id');

        $query = Project::query()
            ->with([
                'organization:id,title',
                'requiredSkills.skill:id,name',
            ]);

        $query->where('status', 'open')
            ->whereNotIn('confidentiality', ['restricted'])
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', now()->toDateString());
            })
            ->where(function ($q) {
                $q->whereNull('application_deadline')
                    ->orWhere('application_deadline', '>=', now()->toDateString());
            })
            ->where(function ($subQ) use ($organizationId) {
                $subQ->where('confidentiality', 'public')
                    ->orWhere(function ($innerQ) use ($organizationId) {
                        $innerQ->where('confidentiality', 'restricted')
                            ->where('organization_id', $organizationId);
                    });
            });

        $projects = $query->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Accessible projects retrieved successfully.',
            'data' => ProjectResource::collection($projects),
            'request_id' => $requestId,
        ]);
    }

    private function errorResponse(
        string $code,
        string $message,
        int $status,
        string $requestId,
        array $details = [],
    ): JsonResponse {
        $payload = [
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ];

        if ($details !== []) {
            $payload['details'] = $details;
        }

        return response()->json($payload, $status, ['X-Request-ID' => $requestId]);
    }
}