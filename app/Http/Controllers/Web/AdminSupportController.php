<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Admin\SupportController as ApiSupportController;

/**
 * Session-authenticated twin of the admin support inbox API.
 *
 * The panel runs on a normal web session, so its JavaScript authenticates with
 * the session cookie rather than a Bearer token. Every rule — the visibility
 * scope, the claim-on-first-reply behaviour, the read tracking — is inherited
 * from the API controller rather than re-implemented, so the two can never
 * disagree about who may read a thread.
 *
 * There is nothing to override: the support payloads contain no route-built
 * links, so the API controller's output is already browser-appropriate.
 */
class AdminSupportController extends ApiSupportController {}
