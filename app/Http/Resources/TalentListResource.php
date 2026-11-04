<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One shortlist.
 *
 * `candidates` is only present when the relation is loaded — the index does not
 * load it, because a list screen shows the count, not the contents.
 *
 * ⚠️ The candidate's e-mail is deliberately absent. This is a list of people a
 * company has BOOKMARKED, not people it has hired; `TalentProfileResource`
 * withholds the address for the same reason, and a shortlist must not become
 * the side door that leaks it.
 */
class TalentListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $list = $this->resource;

        return [
            'id' => $list->id,
            'name' => $list->name,
            'description' => $list->description,
            'candidates_count' => $list->candidates_count ?? $list->candidates?->count(),
            'creator' => $this->whenLoaded('creator', fn () => $list->creator === null ? null : [
                'id' => $list->creator->id,
                'name' => $list->creator->name,
            ]),
            'candidates' => $this->whenLoaded('candidates', fn () => $list->candidates->values()->map(fn ($candidate) => [
                'id' => $candidate->id,
                'student_id' => $candidate->student_id,
                'name' => $candidate->student?->name,
                'note' => $candidate->note,
                'added_at' => $candidate->created_at?->toIso8601String(),
            ])->all()),
            'created_at' => $list->created_at?->toIso8601String(),
            'updated_at' => $list->updated_at?->toIso8601String(),
        ];
    }
}
