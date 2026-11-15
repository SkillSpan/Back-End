<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ProjectAccessService
{
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
                        $innerQ->where('confidentiality', 'restricted')
                            ->whereIn('organization_id', $organizationIds);
                    });
            });
    }

    public function canAccess(Project $project, User $learner): bool
    {
        return $this->canAccessId($project->id, $learner);
    }

    public function canAccessId(int $projectId, User $learner): bool
    {
        return $this->accessibleProjectsQuery($this->activeOrganizationIds($learner))
            ->whereKey($projectId)
            ->exists();
    }
}
