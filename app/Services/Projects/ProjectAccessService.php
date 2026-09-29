<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The single source of truth for "which projects may this learner discover?".
 *
 * Extracted so that project discovery, project details, and the recommendation
 * list cannot drift apart. Before this existed, ProjectController resolved the
 * learner's organization with
 *
 *     DB::table('organization_members')->where('user_id', $id)->value('organization_id')
 *
 * which ignored the membership `status` column entirely. `organization_members`
 * has `status enum('active','invited','removed')` defaulting to 'active', so a
 * learner whose membership had been **removed** (or who had only ever been
 * *invited*) still resolved to that organization and could see its restricted
 * projects — while ProjectMatchingSnapshotService::isAuthorized correctly
 * required `wherePivot('status', 'active')` and would refuse to match them.
 * Discovery was therefore strictly more permissive than matching.
 *
 * The single `->value()` call was also arbitrary: a learner belonging to more
 * than one organization got whichever row the database happened to return
 * first, hiding the other organization's restricted projects.
 */
class ProjectAccessService
{
    /**
     * The learner's organization ids with an ACTIVE membership.
     *
     * `invited` and `removed` memberships grant nothing. An empty array is the
     * normal case for a learner who belongs to no organization, and simply
     * means "public projects only" — it must never be treated as an error.
     *
     * @return list<int>
     */
    public function activeOrganizationIds(User $learner): array
    {
        return DB::table('organization_members')
            ->where('user_id', $learner->id)
            ->where('status', 'active')
            ->pluck('organization_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Base query for the projects a learner is allowed to discover.
     *
     * Rules, mirroring ProjectAvailabilityService plus the confidentiality
     * policy:
     *   - status must be `open`;
     *   - `end_date` and `application_deadline`, when set, must not have passed;
     *   - `public` projects are visible to everyone;
     *   - `restricted` projects are visible only to an active member of the
     *     owning organization.
     *
     * @param  list<int>  $organizationIds  from activeOrganizationIds()
     * @param  array<int, string>  $with
     */
    public function accessibleProjectsQuery(array $organizationIds, array $with = []): Builder
    {
        $query = Project::query();

        if ($with !== []) {
            $query->with($with);
        }

        return $query->where('status', 'open')
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', now()->toDateString());
            })
            ->where(function ($q) {
                $q->whereNull('application_deadline')
                    ->orWhere('application_deadline', '>=', now()->toDateString());
            })
            ->where(function ($subQ) use ($organizationIds) {
                $subQ->where('confidentiality', 'public')
                    ->orWhere(function ($innerQ) use ($organizationIds) {
                        // whereIn with an empty list compiles to a false
                        // predicate, so a learner with no active membership
                        // simply sees no restricted projects.
                        $innerQ->where('confidentiality', 'restricted')
                            ->whereIn('organization_id', $organizationIds);
                    });
            });
    }

    /**
     * Whether the learner may see this specific project.
     *
     * Used for single-project checks; prefer restricting a query with
     * accessibleProjectsQuery() when checking many projects, to avoid N+1.
     */
    public function canAccess(Project $project, User $learner): bool
    {
        return $this->canAccessId($project->id, $learner);
    }

    /**
     * Whether the learner may see the project with this id.
     *
     * Single-query form of canAccess(), for callers that only hold an id.
     *
     * A project that does not exist and a project the learner may not access
     * both return `false`, deliberately: callers use this to decide whether to
     * reveal anything, and distinguishing the two would leak the existence of
     * restricted projects.
     */
    public function canAccessId(int $projectId, User $learner): bool
    {
        return $this->accessibleProjectsQuery($this->activeOrganizationIds($learner))
            ->whereKey($projectId)
            ->exists();
    }
}
