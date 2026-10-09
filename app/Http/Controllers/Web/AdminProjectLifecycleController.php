<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\ProjectManagementController;
use App\Http\Requests\ProjectReviewDecisionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Session-authenticated twin of the project lifecycle endpoints.
 *
 * WHY THIS EXISTS
 * ---------------
 * The admin projects panel is served over the normal web session (a cookie),
 * but the lifecycle transitions live under `/api/v1/projects/{id}/*`, which is
 * behind `auth:sanctum` — i.e. it expects a BEARER TOKEN. `routes/api.php`
 * routes are not given the session middleware, so Sanctum never sees the
 * panel's cookie and answers 401.
 *
 * The panel's `api()` helper treats a 401 as "your session has ended" and
 * navigates to /admin/login. That is exactly what an operator saw: pressing
 * Submit (or Approve / Request changes / Reject / Open) threw them out of the
 * page instead of moving the project. The same failure had already been solved
 * once for the skill picker — see Web\SkillsReferenceController and the
 * `/admin/api/skills/taxonomy` route.
 *
 * AUTHORIZATION IS UNCHANGED
 * --------------------------
 * This class adds no rules of its own. Every transition still goes through
 * ProjectLifecycleService, which decides from the STORED owner_id plus the
 * actor's roles (`assertMayManage` / `assertMayReview`) rather than from the
 * guard. Reusing it verbatim is therefore safe: a session admin gets exactly
 * the authority a token-holding admin gets, and a project owned by someone
 * else still answers 403 PROJECT_NOT_OWNED / PROJECT_REVIEW_SELF_FORBIDDEN.
 *
 * The transitions stay defined in exactly one place — these methods only
 * re-declare the parent's on the session, mirroring the `Web\Admin* extends
 * Api\*` precedent used by AdminOrganizationController, AdminProjectController
 * and SkillsReferenceController.
 */
class AdminProjectLifecycleController extends ProjectManagementController
{
    /**
     * POST /admin/api/projects/{project}/submit — draft | changes_requested → submitted.
     */
    public function submit(Request $request, int $project): JsonResponse
    {
        return parent::submit($request, $project);
    }

    /**
     * POST /admin/api/projects/{project}/approve — submitted → approved.
     */
    public function approve(ProjectReviewDecisionRequest $request, int $project): JsonResponse
    {
        return parent::approve($request, $project);
    }

    /**
     * POST /admin/api/projects/{project}/request-changes — submitted → changes_requested.
     */
    public function requestChanges(ProjectReviewDecisionRequest $request, int $project): JsonResponse
    {
        return parent::requestChanges($request, $project);
    }

    /**
     * POST /admin/api/projects/{project}/reject — submitted → rejected.
     */
    public function reject(ProjectReviewDecisionRequest $request, int $project): JsonResponse
    {
        return parent::reject($request, $project);
    }

    /**
     * POST /admin/api/projects/{project}/open — approved → open.
     */
    public function open(Request $request, int $project): JsonResponse
    {
        return parent::open($request, $project);
    }
}
