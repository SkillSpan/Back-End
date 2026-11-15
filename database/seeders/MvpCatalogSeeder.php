<?php

namespace Database\Seeders;

use App\Models\CareerRole;
use App\Models\Specialization;
use App\Support\ReferenceCache;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class MvpCatalogSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->ensureMergedSpecializations();
        $this->activateMvpSpecializations();
        $this->deactivateOutOfScopeSpecializations();
        $this->deactivateOutOfScopeCareerRoles();

        $this->flushReferenceCache();

        $this->report();
    }

    private function flushReferenceCache(): void
    {
        ReferenceCache::flush();
    }

    private function ensureMergedSpecializations(): void
    {
        $merged = config('mvp.merged_specializations', []);

        if (! is_array($merged)) {
            return;
        }

        foreach ($merged as $targetName => $legacyNames) {
            if (! is_string($targetName) || $targetName === '') {
                continue;
            }

            $target = Specialization::firstOrCreate(
                ['name' => $targetName],
                [
                    'description' => 'Networks, cloud platforms, and the engineering that runs them.',
                    'is_active' => true,
                    'is_free_track' => false,
                ],
            );

            $roleIds = $this->priorityRoleIds($targetName);

            foreach ((array) $legacyNames as $legacyName) {
                $legacy = Specialization::where('name', $legacyName)->first();

                if (! $legacy) {
                    continue;
                }

                $roleIds = array_merge(
                    $roleIds,
                    $legacy->careerRoles()->pluck('career_roles.id')->map(fn ($id) => (int) $id)->all(),
                );
            }

            $roleIds = array_values(array_unique(array_filter($roleIds)));

            if ($roleIds !== []) {
                $target->careerRoles()->syncWithoutDetaching($roleIds);
            }
        }
    }

    private function activateMvpSpecializations(): void
    {
        $names = $this->mvpSpecializationNames();

        if ($names === []) {
            return;
        }

        Specialization::query()
            ->whereIn('name', $names)
            ->where('is_active', false)
            ->update(['is_active' => true]);
    }

    private function deactivateOutOfScopeSpecializations(): void
    {
        $names = $this->mvpSpecializationNames();

        if ($names === []) {
            return;
        }

        Specialization::query()
            ->whereNotIn('name', $names)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    private function deactivateOutOfScopeCareerRoles(): void
    {
        $slugs = config('mvp.inactive_career_role_slugs', []);

        if (! is_array($slugs) || $slugs === []) {
            return;
        }

        $slugs = array_values(array_filter(
            array_map(fn ($slug) => is_string($slug) ? trim($slug) : '', $slugs),
        ));

        if ($slugs === []) {
            return;
        }

        CareerRole::query()
            ->whereIn('slug', $slugs)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    private function mvpSpecializationNames(): array
    {
        $names = array_merge(
            (array) config('mvp.specializations', []),
            [config('mvp.free_track')],
        );

        return array_values(array_filter(
            array_map(fn ($name) => is_string($name) ? trim($name) : '', $names),
        ));
    }

    private function priorityRoleIds(string $specializationName): array
    {
        $slugs = config('mvp.career_role_priority.'.$specializationName, []);

        if (! is_array($slugs) || $slugs === []) {
            return [];
        }

        return CareerRole::query()
            ->whereIn('slug', $slugs)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function report(): void
    {
        if ($this->command === null) {
            return;
        }

        $activeSpecializations = Specialization::where('is_active', true)->count();
        $activeRoles = CareerRole::where('is_active', true)->count();

        $this->command->info(sprintf(
            'MVP catalogue applied: %d active specialization(s), %d active career role(s).',
            $activeSpecializations,
            $activeRoles,
        ));
    }
}
