<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Admin\ProfileController as ApiProfileController;

/**
 * Session-authenticated twin of the admin profile API.
 *
 * The panel runs on a normal web session, so its JavaScript authenticates with
 * the session cookie rather than a Bearer token. Every rule — the self-only
 * scope, the avatar processing, the current-password check — is inherited from
 * the API controller rather than re-implemented, so the two can never disagree
 * about what an account is allowed to change.
 *
 * There is nothing to override. The one route-built value in the payload is
 * `avatar_url`, and it deliberately points at the browser route
 * (`admin.profile.avatar`) rather than at an API path: the image is fetched by
 * an `<img>` tag in both cases, so a session URL is the correct one for the
 * panel and for any browser-based consumer.
 */
class AdminProfileController extends ApiProfileController {}
