<?php

namespace App\Http\Middleware;

use App\Traits\AuditsActions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationIsApproved
{
    use AuditsActions;

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
            $pending = $blocking->verification_status === 'pending';

            // AUTH-04 — a refused call is recorded. Only the membership's own
            // status is written; nothing about the organization's data is.
            $this->audit(
                actorId: $user?->id,
                action: 'organization.access_denied',
                entityType: 'organization',
                entityId: $blocking->id,
                after: ['verification_status' => $blocking->verification_status, 'path' => $request->path()],
                requestId: (string) $request->header('X-Request-ID'),
                ipAddress: $request->ip(),
                userAgent: (string) $request->userAgent(),
            );

            return response()->json([
                'success' => false,
                // A stable code, because the company dashboard has to tell
                // "still under review" (→ the pending screen) from "refused"
                // (→ access denied) without parsing English prose.
                'code' => $pending ? 'ORGANIZATION_PENDING' : 'ORGANIZATION_NOT_APPROVED',
                'message' => $pending
                    ? 'Your organization is still pending approval. Please wait until it has been reviewed.'
                    : 'Your organization registration was rejected. Please contact support for more information.',
            ], 403);
        }

        return $next($request);
    }
}
