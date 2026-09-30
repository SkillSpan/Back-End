<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Admin\ProjectController as ApiProjectController;

/**
 * Session-authenticated twin of the admin projects API.
 *
 * The panel runs on a normal web session, so its JavaScript authenticates
 * with the session cookie rather than a Bearer token. Rather than duplicate
 * the listing, detail and cancellation logic, this class inherits it from the
 * API controller — pagination, search, filters, the cancel authorization and
 * its audit entry all keep working unchanged.
 *
 * There is nothing to override: unlike the organizations panel, the project
 * responses carry no route-built download links, so the API controller's
 * output is already browser-appropriate.
 */
class AdminProjectController extends ApiProjectController {}
