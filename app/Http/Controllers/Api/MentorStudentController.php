<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CommunicationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreConnectionRequest;
use App\Http\Resources\ConnectionResource;
use App\Http\Resources\StudentSummaryResource;
use App\Services\MentorStudentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MentorStudentController extends Controller
{
    public function __construct(private readonly MentorStudentService $mentorService) {}

    /**
     * GET /api/v1/mentor/students
     * Permitted student retrieval — visibility rules enforced in the service.
     */
    public function students(Request $request): JsonResponse
    {
        $mentorId = $request->user()->id;
        $students = $this->mentorService->getPermittedStudents($mentorId);

        $data = $students->map(fn ($profile) => [
            'id' => $profile->user_id,
            'name' => $profile->user?->name,
            'email' => $profile->user?->email,
            'university_name' => $profile->university_name,
            'specialization' => $profile->specialization,
            'career_status' => $profile->career_status,
            'visibility' => $profile->visibility,
            'completeness_percent' => $profile->completeness_percent,
        ])->values();

        return response()->json([
            'success' => true,
            'message' => 'Permitted students retrieved successfully.',
            'data' => $data,
        ], 200, ['X-Request-ID' => $this->requestId($request)]);
    }

    /**
     * GET /api/v1/mentor/students/{student}
     * Student summary retrieval — visibility enforced.
     */
    public function studentSummary(Request $request, int $student): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $summary = $this->mentorService->getStudentSummary(
                $request->user()->id,
                $student,
            );

            return (new StudentSummaryResource($summary))
                ->response()
                ->header('X-Request-ID', $requestId);
        } catch (CommunicationException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }
    }

    /**
     * POST /api/v1/mentor/connections
     * Mentor/project connection creation with availability + eligibility validation.
     */
    public function connect(StoreConnectionRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);

        try {
            $connection = $this->mentorService->createConnection(
                mentorId: $request->user()->id,
                studentId: $request->input('student_id'),
                projectId: $request->input('project_id'),
                initiatedBy: $request->input('initiated_by', 'mentor'),
                request: $request,
            );

            return (new ConnectionResource($connection))
                ->response()
                ->setStatusCode(201)
                ->header('X-Request-ID', $requestId);
        } catch (CommunicationException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }
    }

    /**
     * GET /api/v1/mentor/connections
     * List mentor's connections.
     */
    public function connections(Request $request): JsonResponse
    {
        $connections = $this->mentorService->getMentorConnections(
            $request->user()->id,
        );

        return response()->json([
            'success' => true,
            'message' => 'Connections retrieved successfully.',
            'data' => ConnectionResource::collection($connections->items()),
            'meta' => [
                'current_page' => $connections->currentPage(),
                'last_page' => $connections->lastPage(),
                'per_page' => $connections->perPage(),
                'total' => $connections->total(),
            ],
        ], 200, ['X-Request-ID' => $this->requestId($request)]);
    }

    /**
     * PATCH /api/v1/mentor/connections/{connection}
     * Update connection status (accept, disconnect, archive).
     */
    public function updateConnection(Request $request, int $connection): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'string', 'in:active,disconnected,archived'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $requestId = $this->requestId($request);

        try {
            $updated = $this->mentorService->updateConnectionStatus(
                connectionId: $connection,
                status: $request->input('status'),
                reason: $request->input('reason'),
                actorId: $request->user()->id,
                request: $request,
            );

            return (new ConnectionResource($updated))
                ->response()
                ->header('X-Request-ID', $requestId);
        } catch (CommunicationException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
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
