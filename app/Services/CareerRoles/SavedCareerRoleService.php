<?php

namespace App\Services\CareerRoles;

use App\Models\CareerRole;
use App\Models\SavedCareerRole;
use App\Models\User;
use Illuminate\Support\Collection;

class SavedCareerRoleService
{
    public function list(User $user): Collection
    {
        return SavedCareerRole::query()
            ->where('user_id', $user->id)
            ->with(['careerRole' => fn ($query) => $query
                ->selectable()
                ->withCount('roleSkills as skills_count')])
            ->latest('id')
            ->get()
            ->filter(fn (SavedCareerRole $saved) => $saved->careerRole !== null)
            ->values();
    }

    public function save(User $user, CareerRole $careerRole): SavedCareerRole
    {
        return SavedCareerRole::query()->firstOrCreate([
            'user_id' => $user->id,
            'career_role_id' => $careerRole->id,
        ]);
    }

    public function remove(User $user, CareerRole $careerRole): bool
    {
        $deleted = SavedCareerRole::query()
            ->where('user_id', $user->id)
            ->where('career_role_id', $careerRole->id)
            ->delete();

        return $deleted > 0;
    }

    public function payload(SavedCareerRole $saved): array
    {
        $careerRole = $saved->careerRole;

        return [
            'id' => $careerRole->id,
            'title' => $careerRole->title,
            'slug' => $careerRole->slug,
            'version' => (int) $careerRole->version,
            'status' => $careerRole->status,
            'description' => $careerRole->description,
            'effective_date' => $careerRole->effective_date?->toDateString(),
            'skills_count' => (int) ($careerRole->skills_count ?? $careerRole->roleSkills()->count()),
            'is_saved' => true,
            'saved_at' => $saved->created_at?->toIso8601String(),
        ];
    }
}
