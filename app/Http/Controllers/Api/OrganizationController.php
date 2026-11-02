<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service endpoints for an organization's own admin (as opposed to
 * App\Http\Controllers\Api\Admin\OrganizationController, which is the
 * platform admin review workflow). Every route here sits behind
 * 'auth:sanctum' + 'organization.approved', so a pending or rejected
 * organization account cannot reach it even with a valid Sanctum token.
 */
class OrganizationController extends Controller
{
    /**
     * GET /api/organization/profile
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();

        // The organization returned and the organization that establishes
        // admin rights MUST be the same row. This previously used
        // organizations()->first() for the payload while the permission
        // check was a separate exists() across *all* of the user's
        // memberships — so a user who administered organization B but was
        // only a plain member of organization A could read A's profile.
        // The pivot status is filtered too, so a membership marked
        // 'removed' no longer grants access.
        $organization = $user->organizations()
            ->wherePivot('role_in_org', 'admin')
            ->wherePivot('status', 'active')
            ->first();

        if (! $organization) {
            // Distinguish "no organization at all" from "not an active admin
            // of one" purely to keep the existing UX copy; both are 403.
            if (! $user->organizations()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This account is not linked to any organization.',
                ], 403);
            }

            return response()->json([
                'success' => false,
                'message' => 'Only an organization admin can view this profile.',
            ], 403);
        }

        $fields = $this->profileFields($organization);

        return response()->json([
            'success' => true,
            'message' => 'Organization profile retrieved successfully.',
            'data' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'type' => $organization->type,
                'verification_status' => $organization->verification_status,
                'verified_at' => $organization->verified_at?->toIso8601String(),
                'contact_email' => $organization->contact_email,
                'contact_phone' => $organization->contact_phone,
                'website' => $organization->website,
                'industry' => $organization->industry,
                'company_size' => $organization->company_size,
                'country' => $organization->country,
                'city' => $organization->city,
                'address' => $organization->address,
                'description' => $organization->description,
                // PROF-05 / AC-04 — the dashboard's Organization Status card
                // renders a bar from the percentage and names the fields that
                // are still missing. Both are computed here so the browser
                // never decides what "complete" means.
                'profile_completeness' => $this->completeness($fields),
                'profile_fields' => $fields,
            ],
        ], 200);
    }

    /**
     * The profile fields that count towards completeness.
     *
     * Only columns that actually exist on `organizations` are listed: a field
     * with no storage behind it would sit at "missing" forever and cap
     * completeness below 100 % for every company, which is worse than not
     * showing it. Each `key` matches an existing `company.ws.field.*` label in
     * the company workspace i18n, so the UI can name it without a lookup table.
     *
     * @return array<int, array{key: string, done: bool}>
     */
    private function profileFields($organization): array
    {
        $has = static fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null;

        return [
            ['key' => 'info', 'done' => $has($organization->name) && $has($organization->type)],
            ['key' => 'contact', 'done' => $has($organization->contact_email) || $has($organization->contact_phone)],
            ['key' => 'address', 'done' => $has($organization->country) && $has($organization->city)],
            ['key' => 'size', 'done' => $has($organization->company_size)],
            ['key' => 'website', 'done' => $has($organization->website)],
            ['key' => 'description', 'done' => $has($organization->description)],
        ];
    }

    /**
     * @param  array<int, array{key: string, done: bool}>  $fields
     */
    private function completeness(array $fields): int
    {
        if ($fields === []) {
            return 0;
        }

        $done = count(array_filter($fields, static fn (array $field) => $field['done'] === true));

        return (int) round($done / count($fields) * 100);
    }
}
