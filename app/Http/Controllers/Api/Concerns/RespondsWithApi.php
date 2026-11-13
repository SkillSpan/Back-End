<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Exceptions\ProjectException;
use App\Support\SafeLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

trait RespondsWithApi
{
    protected function requestId(Request $request): string
    {
        return (string) ($request->header('X-Request-ID') ?: Str::uuid());
    }

    protected function success(
        string $message,
        mixed $data = null,
        int $status = 200,
        ?string $requestId = null,
    ): JsonResponse {
        $requestId ??= (string) Str::uuid();

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'request_id' => $requestId,
        ], $status, ['X-Request-ID' => $requestId]);
    }

    protected function failure(
        string $code,
        string $message,
        int $status,
        ?string $requestId = null,
        array $details = [],
    ): JsonResponse {
        $requestId ??= (string) Str::uuid();

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

    protected function failureFrom(ProjectException $e, string $requestId): JsonResponse
    {
        return $this->failure($e->codeName, $e->getMessage(), $e->status, $requestId, $e->details);
    }

    protected function unexpected(Throwable $e, string $requestId, array $context = []): JsonResponse
    {
        Log::error('Sprint workflow request failed.', array_merge([
            'request_id' => $requestId,
            'failure_reason' => SafeLog::reason($e),
        ], $context));

        return $this->failure('REQUEST_FAILED', 'The request could not be completed.', 500, $requestId);
    }

    protected function notFound(string $code, string $message, array $details = [], ?string $requestId = null): JsonResponse
    {
        return $this->failure($code, $message, 404, $requestId, $details);
    }
}
