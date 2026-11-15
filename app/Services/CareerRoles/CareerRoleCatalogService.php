<?php

namespace App\Services\CareerRoles;

use App\Models\CareerRole;
use App\Models\Specialization;
use Illuminate\Database\Eloquent\Builder;

class CareerRoleCatalogService
{
    public function selectableQuery(?Specialization $specialization = null): Builder
    {
        $query = CareerRole::query()->selectable();

        return $this->applyPriorityOrdering($query, $this->prioritySlugs($specialization));
    }

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

            if ($slug !== '' && ! in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

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

    private function applyNeutralOrdering(Builder $query): Builder
    {
        return $query->latest('career_roles.version')->orderByDesc('career_roles.id');
    }
}
