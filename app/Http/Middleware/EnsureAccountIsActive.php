<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks any authenticated request from an account that is no longer in
 * good standing, and revokes that account's tokens while doing so.
 *
 * Sanctum authenticates purely from personal_access_tokens, so it has no
 * notion of application account standing: a token issued before a
 * suspension kept working for its full lifetime (14 days by default).
 * The role middlewares only checked soft deletion, and several route
 * groups behind 'auth:sanctum' had no role middleware at all — so this
 * has to be applied to every authenticated group, not per-controller.
 *
 * Must run after 'auth:sanctum' so $request->user() is resolved.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ($user->trashed() || $user->status !== 'active')) {
            // Suspension has to end the session, not merely block the next
            // login attempt. Deleting the tokens here means the account
            // cannot keep acting even if the response is ignored.
            $user->tokens()->delete();

            return response()->json([
                'code' => 'ACCOUNT_DISABLED',
                'message' => 'This account is no longer active.',
            ], 403);
        }

        return $next($request);
    }
}
