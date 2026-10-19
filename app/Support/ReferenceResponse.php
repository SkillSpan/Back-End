<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Builds the JSON envelope for the read-only reference endpoints with a cache
 * policy the browser can actually use.
 *
 * WHY THIS EXISTS
 * ---------------
 * Laravel answers a JSON response with `Cache-Control: no-cache, private`, so a
 * browser re-downloads the list on every page load. `/reference/universities`
 * is 541 KB of JSON — on a phone that is seconds of transfer, and it was being
 * paid again on every visit even after the server-side cache (see
 * {@see ReferenceCache}) removed the database round trip.
 *
 * Every list here is public, seeded data that is identical for every caller, so
 * a shared one-hour cache is safe. The ETag is derived from the payload itself,
 * which means a redeploy that changes the seed data produces a different ETag
 * and the browser revalidates instead of serving a stale list forever.
 *
 * A revalidation therefore costs a few hundred bytes (304) instead of the full
 * payload.
 */
final class ReferenceResponse
{
    /**
     * One hour. Reference data changes only when a seeder runs, which happens at
     * container start, and the ETag catches that on the next revalidation.
     */
    public const MAX_AGE_SECONDS = 60 * 60;

    /**
     * Keep serving the cached copy while a revalidation runs in the background,
     * so a user never waits on the network for a list they already have.
     */
    public const STALE_WHILE_REVALIDATE_SECONDS = 24 * 60 * 60;

    /**
     * @param  bool  $shared  true for the public dropdown endpoints, false for an
     *                        authenticated endpoint — the payload is still not
     *                        user-specific, but a shared cache in front of an
     *                        token-protected route would hand it to a guest.
     */
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

        // `isNotModified()` returns a bool and mutates the response into a
        // bodyless 304 when the caller's If-None-Match already matches, so it
        // must be called for its side effect rather than returned.
        $response->isNotModified($request);

        return $response;
    }
}
