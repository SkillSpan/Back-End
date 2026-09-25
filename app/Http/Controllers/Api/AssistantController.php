<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ReadinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AskAssistantRequest;
use App\Http\Requests\ReportAssistantInteractionRequest;
use App\Http\Resources\AssistantInteractionResource;
use App\Services\Assistant\AssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * US-REC-01 — assistant endpoints. Thin controller mirroring
 * {@see IntelligenceController}: validation via FormRequest,
 * orchestration in AssistantService, presentation in the resource.
 * Routes sit behind auth:sanctum + account.active + role:learner.
 *
 * This class never generates assistant prose. Laravel is the gateway:
 * authorisation, scope, persistence, and routing only. It does relay the
 * service's reply to the caller — the one thing it may pass through verbatim —
 * because a gateway that swallows the answer is not a gateway.
 */
class AssistantController extends Controller
{
    public function __construct(private readonly AssistantService $assistantService) {}

    public function ask(AskAssistantRequest $request): JsonResponse
    {
        $requestId = $this->requestId($request);
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

        try {
            $answer = $this->assistantService->ask(
                $studentProfile,
                $request->validated(),
                $requestId,
            );

            /*
             * The reply is attached to the resource rather than written to the
             * model: §12.5 data minimisation means it is never persisted, but it
             * still has to reach the client or the UI has nothing to render.
             *
             * 201 because a resource was genuinely created and is being
             * returned. `data.response_status` is the field that says whether a
             * model answered — the two answer different questions, and a 201
             * with response_status "failed" is a coherent pair (see
             * AssistantService::ask()).
             */
            $resource = new AssistantInteractionResource($answer->interaction);
            $resource->answer = $answer;

            return $resource
                ->response()
                ->setStatusCode(201)
                ->header('X-Request-ID', $requestId);
        } catch (ReadinessException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (ValidationException $e) {
            /*
             * Backstop only. A refusal on `intent` — the assistant scope
             * decision (§12.5 / BR-01) — never arrives here: AskAssistantRequest
             * is validated during route resolution, so it emits its own
             * stable code from failedValidation(). Anything reaching this
             * point is a plain payload error.
             */
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'The request could not be processed.',
                'errors' => $e->errors(),
                'request_id' => $requestId,
            ], 422, ['X-Request-ID' => $requestId]);
        } catch (Throwable $e) {
            Log::error('Assistant request failed.', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'intent' => $request->input('intent'),
                'failure_reason' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'ASSISTANT_FAILED',
                'The assistant request could not be completed.',
                500,
                $requestId,
            );
        }
    }

    public function report(ReportAssistantInteractionRequest $request, int $interaction): JsonResponse
    {
        $requestId = $this->requestId($request);
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

        try {
            $reported = $this->assistantService->report(
                $studentProfile,
                $interaction,
                $request->validated(),
            );

            return (new AssistantInteractionResource($reported))
                ->response()
                ->header('X-Request-ID', $requestId);
        } catch (ReadinessException $e) {
            return $this->errorResponse($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
        } catch (ValidationException $e) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'The request could not be processed.',
                'errors' => $e->errors(),
                'request_id' => $requestId,
            ], 422, ['X-Request-ID' => $requestId]);
        } catch (Throwable $e) {
            Log::error('Assistant report failed.', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'interaction_id' => $interaction,
                'failure_reason' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'ASSISTANT_REPORT_FAILED',
                'The assistant interaction could not be reported.',
                500,
                $requestId,
            );
        }
    }

    private function requestId(Request $request): string
    {
        // Same correlation contract as US-INT-01 §5: reuse the incoming
        // X-Request-ID when present, otherwise generate one server-side.
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
