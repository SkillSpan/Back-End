<?php

namespace App\Services\Assistant;

use App\Models\Application;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\Roadmap;
use App\Models\RoadmapAction;
use App\Models\StudentProfile;
use App\Services\Intelligence\IntelligencePayloadBuilder;

/**
 * US-REC-01 — approved learner context snapshot.
 *
 * The assistant equivalent of {@see IntelligencePayloadBuilder}.
 * It assembles ONLY data the learner is authorised to see (BR-11 / §12.5):
 * every single query is explicitly scoped to the authenticated learner.
 *
 * Two rules are enforced structurally rather than by convention:
 *
 *  1. **No fabrication (REC-07).** A source that has no row for this
 *     learner is recorded as `['available' => false, 'reason' => …]`.
 *     It is never silently omitted, and never defaulted to a plausible
 *     value. The assistant service is told "this is unknown", which is
 *     what lets it express uncertainty instead of inventing an answer.
 *
 *  2. **No recomputation (BR-REC-02, BR-06).** Readiness, skill gaps and
 *     the roadmap are read back exactly as they were stored. Nothing is
 *     recalculated or "corrected" here — a stored score that looks wrong
 *     is still the authoritative score, and the assistant explains it.
 *
 * Payload hygiene matches the approved intelligence payload: internal
 * numeric identifiers and validated levels only — no name, no email, no
 * free text belonging to the learner.
 */
class AssistantContextBuilder
{
    /**
     * Build the snapshot plus its reproducibility reference.
     *
     * @return array{snapshot: array<string, mixed>, reference: string}
     */
    public function build(StudentProfile $studentProfile): array
    {
        $careerRoleId = $studentProfile->primary_career_role_id !== null
            ? (int) $studentProfile->primary_career_role_id
            : null;

        $snapshot = [
            // Internal identifier only — the same shape the approved
            // intelligence payload already sends (SRS §8.6, line 418:
            // "receives only validated decision data").
            'student_profile_id' => (int) $studentProfile->id,
            'career_role' => $this->careerRole($studentProfile),
            'readiness' => $this->readiness($studentProfile, $careerRoleId),
            'skill_gaps' => $this->skillGaps($studentProfile),
            'roadmap' => $this->roadmap($studentProfile, $careerRoleId),
            'next_best_action' => $this->nextBestAction($studentProfile, $careerRoleId),
            'project_recommendations' => $this->projectRecommendations($studentProfile),
            'project_applications' => $this->projectApplications($studentProfile),
        ];

        return [
            'snapshot' => $snapshot,
            // Deterministic fingerprint of the data actually sent. Stored on
            // the interaction row instead of the snapshot itself, so the
            // audit trail proves provenance without retaining learner
            // content (SRS v1.1 §9.5, §12.5).
            'reference' => 'ctx-'.substr(hash('sha256', (string) json_encode($snapshot)), 0, 32),
        ];
    }

    /**
     * Scoped lookup for an optional targeted recommendation.
     *
     * Returns null when the row does not exist OR belongs to another
     * learner — the two cases are deliberately indistinguishable to the
     * caller so the endpoint cannot be used to probe for other learners'
     * identifiers (BR-11).
     */
    public function findOwnedRecommendation(StudentProfile $studentProfile, int $recommendationId): ?Recommendation
    {
        return Recommendation::query()
            ->whereKey($recommendationId)
            // student_profiles.user_id is UNIQUE, so scoping by the
            // authenticated learner's user id is exactly equivalent to
            // scoping by student_profile_id — recommendations is keyed on
            // user_id and has no student_profile_id column.
            ->where('user_id', $studentProfile->user_id)
            ->first();
    }

    /**
     * Scoped lookup for an optional targeted project.
     *
     * A learner may discuss a project only if it is genuinely part of
     * their own journey: they applied to it, or it was recommended to
     * them. Merely knowing a project id is not enough (BR-11).
     */
    public function findAccessibleProject(StudentProfile $studentProfile, int $projectId): ?Project
    {
        $userId = $studentProfile->user_id;

        $applied = Application::query()
            ->where('applicant_id', $userId)
            ->where('project_id', $projectId)
            ->exists();

        $recommended = Recommendation::query()
            ->where('user_id', $userId)
            ->where('type', 'project')
            ->where('candidate_type', 'project')
            ->where('candidate_id', $projectId)
            ->exists();

        if (! $applied && ! $recommended) {
            return null;
        }

        return Project::query()->whereKey($projectId)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function careerRole(StudentProfile $studentProfile): array
    {
        $careerRole = $studentProfile->primaryCareerRole;

        if ($careerRole === null) {
            return $this->unavailable('The learner has no primary career role.');
        }

        return [
            'available' => true,
            'id' => (int) $careerRole->id,
            'name' => (string) $careerRole->name,
            'version' => $careerRole->version !== null ? (int) $careerRole->version : null,
            'status' => (string) $careerRole->status,
        ];
    }

    /**
     * Latest stored readiness result — read, never recalculated.
     *
     * @return array<string, mixed>
     */
    private function readiness(StudentProfile $studentProfile, ?int $careerRoleId): array
    {
        $readiness = $studentProfile->readinessResults()
            ->when($careerRoleId, fn ($query, $roleId) => $query->where('career_role_id', $roleId))
            ->orderByDesc('calculated_at')
            ->orderByDesc('id')
            ->first();

        if ($readiness === null) {
            return $this->unavailable('No readiness result has been calculated and stored for this learner.');
        }

        return [
            'available' => true,
            'readiness_result_id' => (int) $readiness->id,
            'career_role_id' => (int) $readiness->career_role_id,
            'career_role_version' => $readiness->career_role_version !== null
                ? (int) $readiness->career_role_version
                : null,
            'score' => $readiness->score !== null ? (float) $readiness->score : null,
            'band' => $readiness->band !== null ? (string) $readiness->band : null,
            'is_provisional' => (bool) $readiness->is_provisional,
            'components' => [
                'skill_match' => $readiness->skill_match_component !== null
                    ? (float) $readiness->skill_match_component
                    : null,
                'practical_experience' => $readiness->practical_experience_component !== null
                    ? (float) $readiness->practical_experience_component
                    : null,
                'assessment_reliability' => $readiness->assessment_reliability_component !== null
                    ? (float) $readiness->assessment_reliability_component
                    : null,
                'profile_completeness' => $readiness->profile_completeness_component !== null
                    ? (float) $readiness->profile_completeness_component
                    : null,
            ],
            'critical_cap_applied' => (bool) $readiness->critical_cap_applied,
            'algorithm_version' => $readiness->algorithm_version !== null
                ? (string) $readiness->algorithm_version
                : null,
            'configuration_version' => $readiness->configuration_version !== null
                ? (string) $readiness->configuration_version
                : null,
            'calculated_at' => $readiness->calculated_at?->toIso8601String(),
        ];
    }

    /**
     * Skill gaps from the most recent SUCCESSFUL decision snapshot.
     *
     * Read back verbatim from `skill_gap_results`; the assistant never
     * re-derives a gap (BR-REC-02).
     *
     * @return array<string, mixed>
     */
    private function skillGaps(StudentProfile $studentProfile): array
    {
        $snapshot = $studentProfile->decisionSnapshots()
            ->where('status', 'succeeded')
            ->orderByDesc('id')
            ->first();

        if ($snapshot === null) {
            return $this->unavailable('No successful intelligence decision is available for this learner.');
        }

        $gaps = $snapshot->skillGapResults()->with('skill')->get();

        if ($gaps->isEmpty()) {
            return $this->unavailable('The latest decision contains no stored skill-gap results.');
        }

        $items = [];

        foreach ($gaps as $gap) {
            $items[] = [
                'skill_id' => (int) $gap->skill_id,
                'skill_name' => (string) $gap->skill?->name,
                'current_level' => $gap->current_level !== null ? (float) $gap->current_level : null,
                'required_level' => $gap->required_level !== null ? (float) $gap->required_level : null,
                'gap' => $gap->gap !== null ? (float) $gap->gap : null,
                'match_score' => $gap->match_score !== null ? (float) $gap->match_score : null,
                'importance_weight' => $gap->importance_weight !== null
                    ? (float) $gap->importance_weight
                    : null,
                'is_critical' => (bool) $gap->is_critical,
                'status' => $gap->status !== null ? (string) $gap->status : null,
                'explanation' => $gap->explanation,
            ];
        }

        return [
            'available' => true,
            'decision_snapshot_id' => (int) $snapshot->id,
            'algorithm_version' => $snapshot->algorithm_version !== null
                ? (string) $snapshot->algorithm_version
                : null,
            'configuration_version' => $snapshot->configuration_version !== null
                ? (string) $snapshot->configuration_version
                : null,
            'calculated_at' => $snapshot->calculated_at?->toIso8601String(),
            'gaps' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function roadmap(StudentProfile $studentProfile, ?int $careerRoleId): array
    {
        $roadmap = $this->latestRoadmap($studentProfile, $careerRoleId);

        if ($roadmap === null) {
            return $this->unavailable('No roadmap has been generated for this learner.');
        }

        $actions = $roadmap->actions()->with('targetSkill')->orderBy('order_index')->get();

        $items = [];

        foreach ($actions as $action) {
            $items[] = [
                'roadmap_action_id' => (int) $action->id,
                'phase' => $action->phase !== null ? (string) $action->phase : null,
                'type' => $action->type !== null ? (string) $action->type : null,
                'target_skill_id' => $action->target_skill_id !== null
                    ? (int) $action->target_skill_id
                    : null,
                'target_skill_name' => $action->targetSkill?->name,
                'title' => (string) $action->title,
                'priority_score' => $action->priority_score !== null
                    ? (float) $action->priority_score
                    : null,
                'order_index' => $action->order_index !== null ? (int) $action->order_index : null,
                'status' => $action->status !== null ? (string) $action->status : null,
                'completion_criteria' => $action->completion_criteria,
                'explanation' => $action->explanation,
            ];
        }

        return [
            'available' => true,
            'roadmap_id' => (int) $roadmap->id,
            'version' => $roadmap->version !== null ? (int) $roadmap->version : null,
            'status' => $roadmap->status !== null ? (string) $roadmap->status : null,
            'career_role_version' => $roadmap->career_role_version !== null
                ? (int) $roadmap->career_role_version
                : null,
            'algorithm_version' => $roadmap->algorithm_version !== null
                ? (string) $roadmap->algorithm_version
                : null,
            'configuration_version' => $roadmap->configuration_version !== null
                ? (string) $roadmap->configuration_version
                : null,
            'explanation' => $roadmap->explanation,
            'generated_at' => $roadmap->generated_at?->toIso8601String(),
            'actions' => $items,
        ];
    }

    /**
     * ROAD-11 — "one explainable Next Best Action".
     *
     * ⚠️ There is no stored Next Best Action flag anywhere in the schema
     * (`roadmap_actions` has priority_score / order_index / fastapi_order
     * / status, but nothing that nominates a single action). This is
     * therefore a READ-SIDE SELECTION, not a recomputation: the first
     * action that is still outstanding, in the order the roadmap already
     * defines. No priority is re-derived and no score is invented.
     *
     * If the intelligence service is meant to nominate the action
     * explicitly, it needs to return that flag — until then this is the
     * most faithful reading of ROAD-11 and is flagged as such.
     *
     * @return array<string, mixed>
     */
    private function nextBestAction(StudentProfile $studentProfile, ?int $careerRoleId): array
    {
        $roadmap = $this->latestRoadmap($studentProfile, $careerRoleId);

        if ($roadmap === null) {
            return $this->unavailable('No roadmap exists, so no next best action can be identified.');
        }

        $action = $roadmap->actions()
            ->with('targetSkill')
            ->where('status', '!=', 'completed')
            ->orderBy('order_index')
            ->orderBy('id')
            ->first();

        if (! $action instanceof RoadmapAction) {
            return $this->unavailable('Every roadmap action is already completed.');
        }

        return [
            'available' => true,
            'roadmap_action_id' => (int) $action->id,
            'roadmap_id' => (int) $roadmap->id,
            'phase' => $action->phase !== null ? (string) $action->phase : null,
            'type' => $action->type !== null ? (string) $action->type : null,
            'target_skill_id' => $action->target_skill_id !== null
                ? (int) $action->target_skill_id
                : null,
            'target_skill_name' => $action->targetSkill?->name,
            'title' => (string) $action->title,
            'completion_criteria' => $action->completion_criteria,
            'explanation' => $action->explanation,
            'selection_basis' => 'first outstanding action in the stored roadmap order',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectRecommendations(StudentProfile $studentProfile): array
    {
        $recommendations = Recommendation::query()
            ->where('user_id', $studentProfile->user_id)
            ->where('type', 'project')
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        if ($recommendations->isEmpty()) {
            return $this->unavailable('No project recommendation has been generated for this learner.');
        }

        $items = [];

        foreach ($recommendations as $recommendation) {
            $items[] = [
                'recommendation_id' => (int) $recommendation->id,
                'candidate_type' => (string) $recommendation->candidate_type,
                'candidate_id' => (int) $recommendation->candidate_id,
                'score' => $recommendation->score !== null ? (float) $recommendation->score : null,
                'eligibility_state' => $recommendation->eligibility_state !== null
                    ? (string) $recommendation->eligibility_state
                    : null,
                'reasons' => $recommendation->reasons,
                'factors' => $recommendation->factors,
                'algorithm_version' => $recommendation->algorithm_version !== null
                    ? (string) $recommendation->algorithm_version
                    : null,
                'generated_at' => $recommendation->generated_at?->toIso8601String(),
            ];
        }

        return ['available' => true, 'recommendations' => $items];
    }

    /**
     * Application context — the learner's own project applications.
     *
     * @return array<string, mixed>
     */
    private function projectApplications(StudentProfile $studentProfile): array
    {
        $applications = Application::query()
            ->where('applicant_id', $studentProfile->user_id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        if ($applications->isEmpty()) {
            return $this->unavailable('The learner has not applied to any project.');
        }

        $items = [];

        foreach ($applications as $application) {
            $items[] = [
                'application_id' => (int) $application->id,
                'project_id' => (int) $application->project_id,
                'status' => $application->status !== null ? (string) $application->status : null,
                'decision_reason' => $application->decision_reason,
                'decided_at' => $application->decided_at?->toIso8601String(),
            ];
        }

        return ['available' => true, 'applications' => $items];
    }

    private function latestRoadmap(StudentProfile $studentProfile, ?int $careerRoleId): ?Roadmap
    {
        return $studentProfile->roadmaps()
            ->when($careerRoleId, fn ($query, $roleId) => $query->where('career_role_id', $roleId))
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Explicit "we do not know this" marker. Never a default value, never
     * a silent omission — REC-07 requires the assistant to be able to
     * distinguish an unknown from a zero.
     *
     * @return array{available: false, reason: string}
     */
    private function unavailable(string $reason): array
    {
        return ['available' => false, 'reason' => $reason];
    }
}
