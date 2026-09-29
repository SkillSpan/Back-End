<?php

namespace App\Http\Middleware;

use App\Models\ProfessionalProfile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates routes to verified mentors only. A "mentor" is a user whose
 * ProfessionalProfile has type='mentor' and verification_status='verified'.
 * Unlike the role-based EnsureUserHasRole, this checks the professional
 * profile directly because mentor status is not a role slug — it is a
 * professional profile attribute with its own verification lifecycle.
 *
 * Must run after 'auth:sanctum' so $request->user() is resolved.
 */
class EnsureUserIsMentor
{
    public function handle(Request $request, Closure $next): Response
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

        if ($user->trashed()) {
            return response()->json([
                'code' => 'ACCOUNT_DISABLED',
                'message' => 'This account is no longer active.',
                'request_id' => $requestId,
            ], 403, ['X-Request-ID' => $requestId]);
        }

        $profile = ProfessionalProfile::where('user_id', $user->id)
            ->where('type', 'mentor')
            ->where('verification_status', 'verified')
            ->exists();

        if (! $profile) {
            return response()->json([
                'code' => 'MENTOR_ONLY',
                'message' => 'Only verified mentors can access this resource.',
                'request_id' => $requestId,
            ], 403, ['X-Request-ID' => $requestId]);
        }

        $request->headers->set('X-Request-ID', $requestId);

        return $next($request);
    }
}
