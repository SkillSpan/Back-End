<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReferenceResponse
{
    public const MAX_AGE_SECONDS = 60 * 60;

    public const STALE_WHILE_REVALIDATE_SECONDS = 24 * 60 * 60;

    public static function make(
        string $message,
        array $data,
        Request $request,
        bool $shared = true,
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        $response = response()->json($payload);

        $response->setEtag(md5((string) json_encode($payload)));
        $response->setCache([
            'public' => $shared,
            'private' => ! $shared,
            'max_age' => self::MAX_AGE_SECONDS,
            'stale_while_revalidate' => self::STALE_WHILE_REVALIDATE_SECONDS,
        ]);

        $response->isNotModified($request);

        return $response;
    }
}
