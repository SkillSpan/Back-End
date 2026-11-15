<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ($user->trashed() || $user->status !== 'active')) {
            $user->tokens()->delete();

            if (! $request->expectsJson()) {
                Auth::guard('web')->logout();

                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }

                return redirect()->route('login');
            }

            return response()->json([
                'code' => 'ACCOUNT_DISABLED',
                'message' => 'This account is no longer active.',
            ], 403);
        }

        return $next($request);
    }
}
