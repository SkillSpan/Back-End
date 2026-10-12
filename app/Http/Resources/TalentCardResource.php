<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One talent card.
 *
 * Array-backed rather than model-backed, the same way StudentSummaryResource
 * is: the payload is assembled from several tables (profile, readiness, skills,
 * evidence) so there is no single model to wrap, and the explicit key list here
 * is the published contract FE-07 codes against.
 *
 * `readiness.score` is the student's REAL score from `readiness_results`.
 * There is deliberately no `match_score`: nothing in the schema stores a
 * talent-to-company match, and inventing one would duplicate the readiness
 * engine rather than reuse it.
 */
class TalentCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'name' => $this['name'] ?? null,
            'career_role' => $this['career_role'] ?? null,
            'specialization' => $this['specialization'] ?? null,
            'university_name' => $this['university_name'] ?? null,
            'career_status' => $this['career_status'] ?? null,
            'enrollment_status' => $this['enrollment_status'] ?? null,
            'availability' => $this['availability'] ?? null,
            'preferred_work_type' => $this['preferred_work_type'] ?? null,
            'completeness_percent' => $this['completeness_percent'] ?? 0,
            'visibility' => $this['visibility'] ?? null,
            'contactable' => $this['contactable'] ?? false,
            'readiness' => [
                'score' => $this['readiness']['score'] ?? null,
                'band' => $this['readiness']['band'] ?? null,
            ],
            'top_skills' => $this['top_skills'] ?? [],
        ];
    }
}
