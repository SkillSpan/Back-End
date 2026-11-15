<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Admin\OrganizationController as ApiOrganizationController;

class AdminOrganizationController extends ApiOrganizationController
{
    protected function proofFileRouteName(): string
    {
        return 'admin.web.organizations.proof-file';
    }
}
