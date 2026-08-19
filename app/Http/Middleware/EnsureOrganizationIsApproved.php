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

        $organization = $user?->organizations()->first();

        if ($organization && $organization->verification_status !== 'verified') {
            return response()->json([
                'success' => false,
                'message' => $organization->verification_status === 'pending'
                    ? 'Your organization is still pending approval. Please wait until it has been reviewed.'
                    : 'Your organization registration was rejected. Please contact support for more information.',
            ], 403);
        }

        return $next($request);
    }
}
