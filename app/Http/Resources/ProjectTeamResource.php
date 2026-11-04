<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One project team and its members.
 *
 * `assignment_state` is exposed because it is the only way a client can tell a
 * current member from one who was removed — the row is kept rather than
 * deleted, so the team's history survives a reassignment.
 */
class ProjectTeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $team = $this->resource;

        return [
            'id' => $team->id,
            'project_id' => $team->project_id,
            'name' => $team->name,
            'members_count' => $team->members->where('assignment_state', 'active')->count(),
            'members' => $team->members->values()->map(fn ($member) => [
                'id' => $member->id,
                'user_id' => $member->user_id,
                'name' => $member->user?->name,
                'email' => $member->user?->email,
                'project_role' => $member->project_role,
                'assignment_state' => $member->assignment_state,
                'joined_at' => $member->joined_at?->toIso8601String(),
                'left_at' => $member->left_at?->toIso8601String(),
            ])->all(),
            'created_at' => $team->created_at?->toIso8601String(),
            'updated_at' => $team->updated_at?->toIso8601String(),
        ];
    }
}
