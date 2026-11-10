<?php

namespace App\Http\Middleware;

use App\Traits\AuditsActions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationIsApproved
{
    use AuditsActions;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $blocking = $user?->organizations()
            ->wherePivot('status', 'active')
            ->whereIn('verification_status', ['pending', 'rejected'])
            ->first();

        if ($blocking) {
            $pending = $blocking->verification_status === 'pending';

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
                'code' => $pending ? 'ORGANIZATION_PENDING' : 'ORGANIZATION_NOT_APPROVED',
                'message' => $pending
                    ? 'Your organization is still pending approval. Please wait until it has been reviewed.'
                    : 'Your organization registration was rejected. Please contact support for more information.',
            ], 403);
        }

        return $next($request);
    }
}
