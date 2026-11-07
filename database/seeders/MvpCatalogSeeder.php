<?php

namespace Database\Seeders;

use App\Models\CareerRole;
use App\Models\Specialization;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Applies the MVP catalogue policy from config/mvp.php to the database.
 *
 * WHAT IT DOES — AND, MORE IMPORTANTLY, WHAT IT NEVER DOES
 * --------------------------------------------------------
 * It only ever writes the two activation flags:
 *
 *   specializations.is_active   true for the MVP tracks, false for the rest
 *   career_roles.is_active      false for the roles the MVP excludes
 *
 * It never deletes, renames or re-slugs anything. In particular:
 *
 *  - a specialization outside the MVP is DEACTIVATED, never removed, so every
 *    historical student profile that mentions it still resolves and can be
 *    reactivated later;
 *  - a career role outside the MVP keeps its row, its required skills, its
 *    questions, its project links and its specialization pivot links — only
 *    the "offered to learners" flag flips;
 *  - no pivot row is ever detached. `syncWithoutDetaching` is used for the one
 *    mapping this seeder creates, so an administrator's curation is preserved;
 *  - `status` (draft / approved / retired) is never touched. Activation and
 *    approval are independent, which is exactly why the column exists.
 *
 * IDEMPOTENT: every step is an upsert or a conditional UPDATE, so running it
 * twice changes nothing the second time. It is safe to run on an existing
 * database and it never requires a fresh migration.
 *
 * POLICY VS LOCAL OVERRIDE: this seeder is the enforcement point of the MVP
 * scope, so it re-applies the policy on every run. An administrator who
 * reactivates a single role from the panel keeps that choice until the seeder
 * runs again; changing the policy permanently is a config edit, not a database
 * edit.
 */
class MvpCatalogSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->ensureMergedSpecializations();
        $this->activateMvpSpecializations();
        $this->deactivateOutOfScopeSpecializations();
        $this->deactivateOutOfScopeCareerRoles();

        $this->report();
    }

    /**
     * Create the merged "Networks & Cloud Computing" track (the MVP lists one
     * entry where the catalog has several) and link it to the union of the
     * legacy tracks' roles, plus its own priority roles.
     *
     * The legacy rows themselves are left completely intact — only their
     * activation flag is cleared later, by the generic deactivation step.
     */
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
                // Never detaches: an administrator's extra links survive.
                $target->careerRoles()->syncWithoutDetaching($roleIds);
            }
        }
    }

    /**
     * Turn the MVP tracks (and the free track) on.
     */
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

    /**
     * Turn everything else off — without deleting a single row.
     */
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

    /**
     * Switch off the roles the MVP excludes. Their skills, questions and
     * links are untouched.
     */
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

    /**
     * @return list<string>
     */
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

    /**
     * The ids of the career roles listed in a specialization's priority list,
     * resolved by slug. Unknown slugs are ignored — the seeder must never
     * invent a role.
     *
     * @return list<int>
     */
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

    /**
     * Operator-facing summary. Printed only when the seeder runs from the
     * console, so a programmatic `$this->seed(...)` stays silent.
     */
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
