<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationIsApproved
{
    /**
     * يمنع أي مستخدم مرتبط بمؤسسة لسا pending أو rejected من الوصول
     * لأي route محمي بهذا الـ middleware — حتى لو نجح بالحصول على توكن
     * صالح بأي طريقة. هذا خط دفاع ثانٍ مستقل عن فحص تسجيل الدخول،
     * حتى لا نعتمد فقط على منع الدخول من الـ login endpoint.
     * لازم يشتغل بعد "auth:sanctum" حتى يكون $request->user() محلول.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Check every ACTIVE membership rather than just the first one: a
        // user linked to several organizations must not pass on the strength
        // of an approved one while another is still pending or rejected, and
        // a membership marked 'removed' is ignored entirely.
        $blocking = $user?->organizations()
            ->wherePivot('status', 'active')
            ->whereIn('verification_status', ['pending', 'rejected'])
            ->first();

        if ($blocking) {
            return response()->json([
                'success' => false,
                'message' => $blocking->verification_status === 'pending'
                    ? 'Your organization is still pending approval. Please wait until it has been reviewed.'
                    : 'Your organization registration was rejected. Please contact support for more information.',
            ], 403);
        }

        return $next($request);
    }
}
