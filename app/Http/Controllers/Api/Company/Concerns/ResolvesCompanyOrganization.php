<?php

namespace App\Http\Controllers\Api\Company\Concerns;

use App\Models\Organization;
use Illuminate\Http\Request;

/**
 * US-COMP-03 / US-COMP-04 — how a company controller finds "the organization".
 *
 * The rule is the same everywhere and it is the one thing that must never
 * drift: the organization comes from the caller's **own admin membership**,
 * never from a route parameter, a query string or a body field. A controller
 * that resolved it any other way would give a company admin a way to read or
 * write another organization's data by changing one id.
 *
 * Both the dashboard and the profile use this trait, so the two cannot
 * disagree about what "admin" means (pivot `role_in_org = admin` AND pivot
 * `status = active`) or about the two 403 codes the frontend branches on.
 */
trait ResolvesCompanyOrganization
{
    protected function resolveOrganization(Request $request): ?Organization
    {
        return $request->user()->organizations()
            ->wherePivot('role_in_org', 'admin')
            ->wherePivot('status', 'active')
            ->first();
    }

    /**
     * Distinguish "this account belongs to no organization" from "this account
     * belongs to one but is not its admin" — both are 403, but the UI shows a
     * different sentence for each.
     *
     * @return array{code: string, reason: string}
     */
    protected function organizationDenial(Request $request): array
    {
        return $request->user()->organizations()->exists()
            ? ['code' => 'NOT_ORGANIZATION_ADMIN', 'reason' => 'not_organization_admin']
            : ['code' => 'NO_ORGANIZATION', 'reason' => 'no_organization'];
    }
}
