<?php

namespace App\Http\Controllers\Api\Company\Concerns;

use App\Models\Organization;
use Illuminate\Http\Request;

trait ResolvesCompanyOrganization
{
    protected function resolveOrganization(Request $request): ?Organization
    {
        return $request->user()->organizations()
            ->wherePivot('role_in_org', 'admin')
            ->wherePivot('status', 'active')
            ->first();
    }

    protected function organizationDenial(Request $request): array
    {
        return $request->user()->organizations()->exists()
            ? ['code' => 'NOT_ORGANIZATION_ADMIN', 'reason' => 'not_organization_admin']
            : ['code' => 'NO_ORGANIZATION', 'reason' => 'no_organization'];
    }
}
