<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();
        $requestId = (string) ($request->header('X-Request-ID') ?: Str::uuid());

        if (! $user) {
            return response()->json([
                'code' => 'UNAUTHENTICATED',
                'message' => 'Authentication is required.',
                'request_id' => $requestId,
            ], 401, ['X-Request-ID' => $requestId]);
        }

        // A soft-deleted account must not be able to keep using a token it
        // obtained before deletion.
        if ($user->trashed()) {
            return response()->json([
                'code' => 'ACCOUNT_DISABLED',
                'message' => 'This account is no longer active.',
                'request_id' => $requestId,
            ], 403, ['X-Request-ID' => $requestId]);
        }

        if (! $user->hasRole($role)) {
            return response()->json([
                'code' => 'LEARNER_ONLY',
                'message' => "Only {$role} accounts can access this resource.",
                'request_id' => $requestId,
            ], 403, ['X-Request-ID' => $requestId]);
        }

        $request->headers->set('X-Request-ID', $requestId);

        return $next($request);
    }
}
