<?php

namespace App\Services\CareerRoles;

use App\Models\CareerRole;
use App\Models\Specialization;
use Illuminate\Database\Eloquent\Builder;

/**
 * The learner-facing career-role catalogue.
 *
 * ONE PLACE THAT ANSWERS TWO QUESTIONS
 * ------------------------------------
 *  1. WHICH roles are listed?  -> every APPROVED role, including the ones that
 *     are not available yet. A role that is not available is returned as
 *     "Coming Soon" (`is_available = false`) instead of being hidden, so a
 *     learner can see what is on the way. Only draft/retired roles are
 *     excluded — they are not published definitions at all.
 *
 *  2. IN WHAT ORDER?  -> available roles first, then the rest. Inside each
 *     block the roles listed for the chosen specialization in config/mvp.php
 *     come first, in the order they are listed; everything else follows in a
 *     stable, neutral order.
 *
 * Availability itself is `career_roles.is_active` — the column the rest of the
 * app already uses. No parallel availability system exists.
 *
 * WHY THE ORDERING IS A SQL CASE, NOT PHP SORTING
 * -----------------------------------------------
 * The list is paginated. Sorting after `paginate()` would only reorder the 15
 * rows of the current page, so the same role could appear on two pages (or on
 * none) and the priority roles would not actually be first across the list.
 * The rank is therefore compiled into the query itself, which means:
 *
 *  - it runs before LIMIT/OFFSET (correct priority on page 1..N);
 *  - it is total: every row gets exactly one rank, so no row can be produced
 *    twice by the join-free CASE;
 *  - it is deterministic: `version DESC, id DESC` breaks the remaining ties,
 *    which keeps pagination stable between requests.
 *
 * The CASE binds slugs as parameters rather than interpolating them, so the
 * config stays data and never becomes SQL.
 */
class CareerRoleCatalogService
{
    /**
     * The base query for the learner-facing list: every approved role, whether
     * available or Coming Soon, ordered available-first.
     *
     * @param  Specialization|null  $specialization  When given, its priority
     *                                               list is applied to the order.
     */
    public function listedQuery(?Specialization $specialization = null): Builder
    {
        $query = CareerRole::query()->where('career_roles.status', 'approved');

        /*
         * Availability rank: 0 for a role a learner can use right now, 1 for a
         * Coming Soon role. Without this a learner's first page would be
         * filled with roles that cannot be started yet.
         */
        $query->orderByRaw('CASE WHEN career_roles.is_active = 1 THEN 0 ELSE 1 END ASC');

        return $this->applyPriorityOrdering($query, $this->prioritySlugs($specialization));
    }

    /**
     * The ordered priority slugs for a specialization.
     *
     * Returns an empty list for the free track and for a specialization the
     * policy file does not mention — both cases mean "neutral order".
     *
     * @return list<string>
     */
    public function prioritySlugs(?Specialization $specialization): array
    {
        if ($specialization === null || $specialization->isFreeTrack()) {
            return [];
        }

        $configured = config('mvp.career_role_priority.'.$specialization->name, []);

        if (! is_array($configured)) {
            return [];
        }

        $slugs = [];

        foreach ($configured as $slug) {
            if (! is_string($slug)) {
                continue;
            }

            $slug = trim($slug);

            // A slug repeated in the config would give one role two ranks;
            // keeping the first occurrence preserves the authored order.
            if ($slug !== '' && ! in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * Apply "priority roles first, in the configured order, then everything
     * else" to a career-role query.
     *
     * A role that is not in the list is never excluded — it is ranked after
     * the priority block (rank = count + 1) and sorted by the neutral
     * tie-breakers. That is what keeps "all active roles are available for
     * every specialization" true while still putting the closest roles first.
     *
     * @param  list<string>  $prioritySlugs
     */
    public function applyPriorityOrdering(Builder $query, array $prioritySlugs): Builder
    {
        if ($prioritySlugs === []) {
            return $this->applyNeutralOrdering($query);
        }

        $cases = [];
        $bindings = [];

        foreach (array_values($prioritySlugs) as $index => $slug) {
            $cases[] = 'WHEN ? THEN '.($index + 1);
            $bindings[] = $slug;
        }

        $fallbackRank = count($prioritySlugs) + 1;

        $query->orderByRaw(
            'CASE career_roles.slug '.implode(' ', $cases).' ELSE '.$fallbackRank.' END ASC',
            $bindings,
        );

        return $this->applyNeutralOrdering($query);
    }

    /**
     * The stable order used inside each rank block (and as the whole order
     * when there is no priority list, e.g. the free track).
     *
     * `version DESC, id DESC` is the order this endpoint has always used, so a
     * caller that does not send `specialization_id` keeps seeing exactly the
     * list it saw before the MVP work — and it is a total order, which is what
     * makes pagination safe.
     */
    private function applyNeutralOrdering(Builder $query): Builder
    {
        return $query->latest('career_roles.version')->orderByDesc('career_roles.id');
    }
}
