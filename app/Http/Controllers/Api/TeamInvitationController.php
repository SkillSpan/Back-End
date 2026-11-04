<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ProjectException;
use App\Http\Controllers\Controller;
use App\Models\TeamInvitation;
use App\Services\Projects\ProjectTeamService;
use App\Support\SafeLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The learner's side of a team invitation — `GET /api/v1/team-invitations/{id}`
 * and `POST /api/v1/team-invitations/{id}/response`.
 *
 * A separate controller from ProjectTeamController because the caller is a
 * different person with a different rule: the team endpoints are the company
 * acting on a project it owns, these two are the invitee acting on an
 * invitation addressed to them. Same service, opposite direction.
 *
 * No `role:` middleware: the invited person is identified by the row, not by a
 * role, and an administrator has to be able to read one to support it. Both
 * rules live in ProjectTeamService.
 */
class TeamInvitationController extends Controller
{
    public function __construct(
        private readonly ProjectTeamService $teamService,
    ) {}

    /**
     * GET /api/v1/team-invitations/{invitation}
     */
    public function show(Request $request, int $invitation): JsonResponse
    {
        $requestId = $this->requestId($request);

        $model = TeamInvitation::query()->find($invitation);

        if ($model === null) {
            return $this->errorResponse(
                'INVITATION_NOT_FOUND',
                'The requested invitation does not exist.',
                404,
                $requestId,
                ['invitation_id' => $invitation],
            );
        }

        try {
            $model = $this->teamService->invitationFor($model, $request->user());
        } catch (ProjectException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        }

        return response()->json([
            'success' => true,
            'message' => 'Invitation retrieved successfully.',
            'data' => $this->payload($model),
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * POST /api/v1/team-invitations/{invitation}/response
     *
     * Body: { "response": "accepted" | "declined" }
     */
    public function respond(Request $request, int $invitation): JsonResponse
    {
        $requestId = $this->requestId($request);

        $model = TeamInvitation::query()->find($invitation);

        if ($model === null) {
            return $this->errorResponse(
                'INVITATION_NOT_FOUND',
                'The requested invitation does not exist.',
                404,
                $requestId,
                ['invitation_id' => $invitation],
            );
        }

        $validator = validator($request->all(), [
            'response' => ['required', 'string', 'in:accepted,declined'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                'The request could not be processed.',
                422,
                $requestId,
                ['errors' => $validator->errors()->messages()],
            );
        }

        try {
            $model = $this->teamService->respond(
                $model,
                $request->user(),
                $validator->validated()['response'],
                $requestId,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (ProjectException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (Throwable $e) {
            Log::error('Invitation response failed.', [
                'request_id' => $requestId,
                'failure_reason' => SafeLog::reason($e),
                'invitation_id' => $invitation,
            ]);

            return $this->errorResponse(
                'INVITATION_FAILED',
                'The invitation could not be updated.',
                500,
                $requestId,
            );
        }

        return response()->json([
            'success' => true,
            'message' => $model->status === TeamInvitation::STATUS_ACCEPTED
                ? 'Invitation accepted.'
                : 'Invitation declined.',
            'data' => $this->payload($model),
            'request_id' => $requestId,
        ], 200, ['X-Request-ID' => $requestId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(TeamInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'status' => $invitation->status,
            'project_role' => $invitation->project_role,
            'message' => $invitation->message,
            'sent_at' => $invitation->sent_at?->toIso8601String(),
            'responded_at' => $invitation->responded_at?->toIso8601String(),
            'project' => $invitation->project === null ? null : [
                'id' => $invitation->project->id,
                'title' => $invitation->project->title,
                'status' => $invitation->project->status,
                'start_date' => $invitation->project->start_date?->toDateString(),
                'organization' => $invitation->project->organization?->name,
            ],
        ];
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
