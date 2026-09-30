<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\SkillsController;
use Illuminate\Http\JsonResponse;

/**
 * Session-authenticated twin of the skills-reference endpoints.
 *
 * WHY THIS EXISTS
 * ---------------
 * The admin panel is served over the normal web session (a cookie), but the
 * skills reference data lives under `/api/v1/skills/*`, which is behind
 * `auth:sanctum` — i.e. it expects a BEARER TOKEN. A browser following the
 * panel's JavaScript has no token to send, so the request came back 401 and
 * the skill picker in the create/edit form stayed empty.
 *
 * `SkillsController::taxonomy()` is pure reference data (no learner context, no
 * user argument) so it is safe to reuse verbatim; this class only re-declares it
 * on the session. The same `Web\Admin* extends Api\*` precedent used by
 * AdminOrganizationController and AdminProjectController.
 */
class SkillsReferenceController extends SkillsController
{
    /**
     * GET /admin/api/skills/taxonomy
     */
    public function taxonomy(): JsonResponse
    {
        return parent::taxonomy();
    }
}
