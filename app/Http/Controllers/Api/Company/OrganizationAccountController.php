<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Api\Company\Concerns\ResolvesCompanyOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\DeactivateOrganizationRequest;
use App\Models\AuthSession;
use App\Models\Organization;
use App\Traits\AuditsActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OrganizationAccountController extends Controller
{
    use AuditsActions;
    use ResolvesCompanyOrganization;

    public function deactivate(DeactivateOrganizationRequest $request, Organization $organization): JsonResponse
    {
        $managed = $this->resolveOrganization($request);

        if ($managed === null) {
            $denial = $this->organizationDenial($request);

            return response()->json([
                'success' => false,
                'message' => 'Only an organization admin can deactivate this account.',
                'code' => $denial['code'],
            ], 403);
        }

        if ((int) $managed->id !== (int) $organization->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only deactivate the organization you administer.',
                'code' => 'ORGANIZATION_MISMATCH',
            ], 403);
        }

        if ($organization->deactivated_at !== null) {
            return response()->json([
                'success' => true,
                'message' => 'This organization account is already deactivated.',
                'data' => $this->payload($organization, 0),
            ]);
        }

        $reason = $request->filled('reason') ? trim((string) $request->input('reason')) : null;
        $members = $organization->members()->wherePivot('status', 'active')->get();

        DB::transaction(function () use ($organization, $members, $reason): void {
            $organization->forceFill([
                'deactivated_at' => now(),
                'deactivation_reason' => $reason === '' ? null : $reason,
            ])->save();

            foreach ($members as $member) {
                $member->forceFill(['status' => 'suspended'])->save();

                AuthSession::where('user_id', $member->id)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);

                $member->tokens()->delete();
            }
        });

        $this->audit(
            actorId: $request->user()->id,
            action: 'organization.deactivated',
            entityType: 'organization',
            entityId: $organization->id,
            before: ['deactivated_at' => null],
            after: [
                'deactivated_at' => $organization->deactivated_at?->toIso8601String(),
                'reason' => $reason,
                'members_suspended' => $members->count(),
            ],
            requestId: $request->attributes->get('request_id'),
            ipAddress: $request->ip(),
            userAgent: (string) $request->userAgent(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Your organization account has been deactivated. Contact support if you want it restored.',
            'data' => $this->payload($organization->fresh(), $members->count()),
        ]);
    }

    private function payload(Organization $organization, int $membersSuspended): array
    {
        return [
            'organization_id' => $organization->id,
            'deactivated_at' => $organization->deactivated_at?->toIso8601String(),
            'deactivation_reason' => $organization->deactivation_reason,
            'members_suspended' => $membersSuspended,
            'redirect_url' => '/login',
        ];
    }
}
