<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One student's full talent profile — the three tabs the prototype drew
 * (skills, projects, evaluation) plus the identity block.
 *
 * Like TalentCardResource this is array-backed: the payload is assembled from
 * `student_profiles`, `readiness_results`, `learner_skills`, `skill_evidences`,
 * `project_team_members` and `evaluations`.
 *
 * ⚠️ The student's email address is NOT part of this contract, on purpose. A
 * company is expected to reach a learner through a recorded contact request
 * (a follow-up task), not by harvesting mailboxes from a list endpoint.
 *
 * ⚠️ The evaluator's identity, the per-dimension comments and
 * `moderation_reason` are withheld for the same reason: they are internal
 * review material. `evaluation.evaluations_count` and `reviewed_at` are what a
 * client should render in place of "reviewed by".
 */
class TalentProfileResource extends JsonResource
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

            // Profile detail
            'bio' => $this['bio'] ?? null,
            'interests' => $this['interests'] ?? [],
            'academic_level' => $this['academic_level'] ?? null,
            'expected_graduation' => $this['expected_graduation'] ?? null,
            'graduation_status' => $this['graduation_status'] ?? false,

            // Tab: skills
            'skills' => $this['skills'] ?? [],

            // Tab: projects
            'projects' => $this['projects'] ?? [],

            // Tab: evaluation
            'evaluation' => [
                'authorized' => $this['evaluation']['authorized'] ?? false,
                'overall_rating' => $this['evaluation']['overall_rating'] ?? null,
                'criteria_scores' => $this['evaluation']['criteria_scores'] ?? [],
                'evaluations_count' => $this['evaluation']['evaluations_count'] ?? 0,
                'reviewed_at' => $this['evaluation']['reviewed_at'] ?? null,
            ],
        ];
    }
}
