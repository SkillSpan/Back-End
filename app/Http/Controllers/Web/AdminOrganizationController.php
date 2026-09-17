<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Admin\OrganizationController as ApiOrganizationController;

/**
 * Session-authenticated twin of the admin organizations API.
 *
 * The review panel runs on a normal web session now, so its JavaScript can
 * no longer authenticate with a Bearer token. Rather than duplicate the
 * review workflow, every action is inherited straight from the API
 * controller — list, show, approve, reject and the proof download all keep
 * working exactly as before, including the audit trail and the notifications.
 *
 * The single difference is the proof-document link: an API client follows it
 * with a token, whereas the browser follows it with a session cookie, so it
 * has to be built from the web route instead.
 */
class AdminOrganizationController extends ApiOrganizationController
{
    protected function proofFileRouteName(): string
    {
        return 'admin.web.organizations.proof-file';
    }
}
