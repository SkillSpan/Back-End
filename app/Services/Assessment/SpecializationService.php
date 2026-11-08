<?php

namespace App\Services\Assessment;

use App\Exceptions\SpecializationException;
use App\Models\CareerRole;
use App\Models\Skill;
use App\Models\Specialization;
use App\Support\ReferenceCache;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Specialization management for the admin page.
 *
 * Works on the existing `specializations` table and the existing
 * `career_role_specialization` pivot — it introduces no parallel model. The
 * "Manage Career Roles" screen is a view over that pivot, and creating a
 * career role reuses CareerRole + CareerRoleSkill.
 *
 * Deletion is deliberately conservative: a specialization that still has
 * career roles linked to it is refused (deactivating is the safe
 * alternative), and the free-track row is protected.
 *
 * EVERY WRITE INVALIDATES THE REFERENCE CACHE. `GET /api/v1/reference/
 * specializations` is cached for twelve hours (the database is remote, so the
 * round trip dominates the endpoint). Without an explicit invalidation an
 * operator would create, rename, activate or deactivate a track and the
 * learner-facing dropdown would keep serving the old list for the rest of the
 * TTL — the change would look saved and do nothing. Every mutating method below
 * therefore calls {@see ReferenceCache::flush()} once it has committed.
 */
class SpecializationService
{
    public function paginate(string $search = '', ?string $status = null, int $perPage = 15): LengthAwarePaginator
    {
        return Specialization::query()
            ->withCount('careerRoles')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($status !== null, fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Specialization
    {
        $specialization = Specialization::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            // Only the seeder may flag a free track.
            'is_free_track' => false,
        ]);

        $this->forgetReferenceCache();

        return $specialization;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Specialization $specialization, array $data): Specialization
    {
        $specialization->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : $specialization->is_active,
        ]);

        $this->forgetReferenceCache();

        return $specialization->refresh();
    }

    public function setActive(Specialization $specialization, bool $active): Specialization
    {
        $specialization->update(['is_active' => $active]);

        $this->forgetReferenceCache();

        return $specialization->refresh();
    }

    public function delete(Specialization $specialization): void
    {
        if ($specialization->isFreeTrack()) {
            throw SpecializationException::freeTrackProtected((int) $specialization->id);
        }

        $linkedRoles = $specialization->careerRoles()->count();

        if ($linkedRoles > 0) {
            throw SpecializationException::inUse((int) $specialization->id, $linkedRoles);
        }

        $specialization->delete();

        $this->forgetReferenceCache();
    }

    /**
     * Drop the cached reference lists after a specialization changed.
     *
     * Centralised so a future mutating method cannot forget it: every write
     * above ends with this call, and the admin endpoints therefore take effect
     * on the very next `GET /api/v1/reference/specializations` instead of up to
     * twelve hours later.
     */
    private function forgetReferenceCache(): void
    {
        ReferenceCache::flush();
    }

    /**
     * Every career role, each flagged with whether it is linked to this
     * specialization. Drives the "Manage Career Roles" checklist.
     *
     * @return array<int, array{id:int,title:string,status:string,attached:bool}>
     */
    public function roleOptions(Specialization $specialization, string $search = ''): array
    {
        $attached = $specialization->careerRoles()
            ->pluck('career_roles.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return CareerRole::query()
            ->when($search !== '', fn ($query) => $query->where('title', 'like', "%{$search}%"))
            ->orderBy('title')
            ->get(['id', 'title', 'status'])
            ->map(fn (CareerRole $role) => [
                'id' => (int) $role->id,
                'title' => (string) $role->title,
                'status' => (string) $role->status,
                'attached' => in_array((int) $role->id, $attached, true),
            ])
            ->all();
    }

    /**
     * Replace the specialization's linked career roles with the given set.
     *
     * @param  array<int, mixed>  $roleIds
     */
    public function syncCareerRoles(Specialization $specialization, array $roleIds): Specialization
    {
        $validIds = CareerRole::query()
            ->whereIn('id', array_map('intval', $roleIds))
            ->pluck('id')
            ->all();

        $specialization->careerRoles()->sync($validIds);

        return $specialization->refresh();
    }

    /**
     * Skills that can be attached to a newly created career role.
     *
     * @return array<int, array{id:int,name:string,slug:string}>
     */
    public function skillOptions(string $search = ''): array
    {
        return Skill::query()
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Skill $skill) => [
                'id' => (int) $skill->id,
                'name' => (string) $skill->name,
                'slug' => (string) $skill->slug,
            ])
            ->all();
    }

    /**
     * Create a career role and attach its required skills.
     *
     * Refuses a title that already maps to an existing role slug, so the
     * panel can never silently overwrite a role by reusing its name.
     *
     * THE NEW ROLE IS CREATED INACTIVE (`is_active = false`).
     *
     * Creating a role used to publish it to every learner immediately, because
     * the column defaults to TRUE. That made "add a role" and "offer this role
     * to the whole student body" the same button, with no review step and no
     * way back other than a manual UPDATE — a half-authored role (no skills
     * yet, skills still being attached) would appear in every picker the moment
     * it was saved.
     *
     * Activation is now an explicit, separate decision taken by an
     * administrator through `POST /admin/api/career-roles/{id}/activate`. Roles
     * that already existed keep whatever activation state they had; only new
     * rows start hidden.
     *
     * @param  array<int, mixed>  $skillIds
     */
    public function createCareerRole(string $title, array $skillIds): CareerRole
    {
        $slug = Str::slug($title);

        if (CareerRole::where('slug', $slug)->exists()) {
            throw SpecializationException::careerRoleTitleTaken($title);
        }

        $role = CareerRole::create([
            'title' => $title,
            'slug' => $slug,
            'version' => 1,
            'status' => 'approved',
            'effective_date' => now()->toDateString(),
            // Explicit, not inherited from the column default: publishing a
            // role is a separate, deliberate step.
            'is_active' => false,
        ]);

        $validSkillIds = Skill::query()
            ->whereIn('id', array_map('intval', $skillIds))
            ->pluck('id')
            ->all();

        foreach ($validSkillIds as $index => $skillId) {
            $role->roleSkills()->updateOrCreate(
                ['skill_id' => $skillId],
                [
                    'required_level' => $index === 0 ? 3.0 : 2.5,
                    'importance_weight' => round(max(0.4, 0.9 - ($index * 0.1)), 3),
                    'is_critical' => $index === 0,
                ]
            );
        }

        return $role;
    }
}
