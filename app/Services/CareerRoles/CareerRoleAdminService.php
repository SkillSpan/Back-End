<?php

namespace App\Services\CareerRoles;

use App\Exceptions\CareerRoleException;
use App\Models\CareerRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CareerRoleAdminService
{
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
