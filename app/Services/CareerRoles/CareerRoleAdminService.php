<?php

namespace App\Services\CareerRoles;

use App\Exceptions\CareerRoleException;
use App\Models\CareerRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Admin-side career-role management.
 *
 * WHY THIS EXISTS
 * ---------------
 * `career_roles.is_active` is what decides whether a role is offered to
 * learners, but until now nothing in the backend could change it: the only
 * ways were a raw UPDATE or re-running a seeder that rewrites the whole policy.
 * That left an operator unable to publish a newly created role (which is now
 * deliberately created inactive) or to retire a single role without editing
 * config and re-seeding.
 *
 * This service is the one place that flips the flag, so the rules live next to
 * the data rather than in a controller:
 *
 *  - activating requires `status = approved`;
 *  - deactivating is always allowed and never deletes anything — the row, its
 *    required skills, its questions and its pivot links all stay;
 *  - nothing else about the role is touched (title, slug, version, status and
 *    the skill mapping are immutable here).
 *
 * It deliberately does NOT invalidate the reference cache: career roles are not
 * part of it (`ReferenceCache` covers universities, countries, specializations
 * and the skill taxonomy). If a career-role list is ever cached, this is the
 * place to add the invalidation.
 */
class CareerRoleAdminService
{
    /**
     * Paginated career roles for the admin list, newest id first so a freshly
     * created role is easy to find.
     *
     * All statuses are returned — the point of the screen is to let an operator
     * see, and fix, roles that are not offered.
     */
    public function paginate(
        string $search = '',
        ?string $status = null,
        ?bool $isActive = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        return CareerRole::query()
            ->withCount('roleSkills as skills_count')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($isActive !== null, fn ($query) => $query->where('is_active', $isActive))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Switch a role on or off for learners.
     *
     * @throws CareerRoleException when activation is requested for a role that
     *                             is not approved
     */
    public function setActive(CareerRole $careerRole, bool $active): CareerRole
    {
        if ($active && $careerRole->status !== 'approved') {
            throw CareerRoleException::cannotActivate(
                (int) $careerRole->id,
                (string) $careerRole->status,
            );
        }

        $careerRole->update(['is_active' => $active]);

        return $careerRole->refresh();
    }
}
