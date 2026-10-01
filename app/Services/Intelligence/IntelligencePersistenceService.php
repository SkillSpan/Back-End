<?php

namespace App\Services\Intelligence;

use App\Exceptions\IntelligenceException;
use App\Models\DecisionSnapshot;
use App\Models\ReadinessResult;
use App\Models\Roadmap;
use App\Models\RoadmapAction;
use App\Models\RoadmapActionPrerequisite;
use App\Models\SkillGapResult;
use Illuminate\Support\Facades\DB;

/**
 * US-INT-01 §22 — atomic persistence for a complete intelligence
 * decision: skill gaps + readiness + roadmap in ONE transaction. If any
 * part fails, nothing persists and previous historical results remain
 * untouched.
 */
class IntelligencePersistenceService
{
    /**
     * @param  array<string, mixed>  $skillGap  validated skill-gap response
     * @param  array<string, mixed>  $readiness  validated readiness response
     * @param  array<string, mixed>|null  $roadmap  validated roadmap response (nullable when disabled)
     * @param  array<string, string>  $skillNameById
     */
    public function persistCompleteDecision(
        DecisionSnapshot $snapshot,
        array $skillGap,
        array $readiness,
        ?array $roadmap,
        array $skillNameById,
        string $algorithmVersion,
        string $configurationVersion,
        string $requestId,
    ): array {
        return DB::transaction(function () use (
            $snapshot,
            $skillGap,
            $readiness,
            $roadmap,
            $skillNameById,
            $algorithmVersion,
            $configurationVersion,
            $requestId,
        ) {
            $readinessResult = $this->persistReadiness(
                $snapshot,
                $readiness,
                $algorithmVersion,
                $configurationVersion,
                $requestId,
            );

            $gapResults = $this->persistSkillGaps($snapshot, $skillGap);

            $roadmapModel = null;

            if ($roadmap !== null) {
                $roadmapModel = $this->persistRoadmap(
                    $snapshot,
                    $roadmap,
                    $skillNameById,
                    $algorithmVersion,
                    $configurationVersion,
                    $requestId,
                );
            }

            $snapshot->update([
                'status' => DecisionSnapshot::STATUS_SUCCEEDED,
                'calculated_at' => now(),
            ]);

            return [
                'snapshot' => $snapshot->fresh(),
                'readiness' => $readinessResult,
                'skill_gaps' => $gapResults,
                'roadmap' => $roadmapModel,
            ];
        });
    }

    private function persistReadiness(
        DecisionSnapshot $snapshot,
        array $result,
        string $algorithmVersion,
        string $configurationVersion,
        string $requestId,
    ): ReadinessResult {
        return ReadinessResult::create([
            'student_profile_id' => $snapshot->student_profile_id,
            'career_role_id' => $snapshot->career_role_id,
            'career_role_version' => $snapshot->career_role_version,
            'decision_snapshot_id' => $snapshot->id,
            'score' => $result['readiness_score'],
            'skill_match_component' => $result['base_readiness_score'],
            'practical_experience_component' => $result['practical_experience_component'] ?? null,
            'assessment_reliability_component' => $result['assessment_reliability_component'] ?? null,
            'profile_completeness_component' => $result['profile_completeness_component'] ?? null,
            /*
             * Optional by design: the cap is Laravel's decision, so the
             * service is not obliged to report it (see
             * IntelligenceResponseValidator::validateReadiness()).
             *
             * Follow-up (US-INT-01): this path should derive the cap from the
             * per-skill `is_critical` + `match_ratio` data the way
             * ReadinessService does, rather than trusting the service. Until
             * then an omitted flag is recorded as "not applied".
             */
            'critical_cap_applied' => (bool) ($result['critical_skill_cap_applied'] ?? false),
            'band' => $result['band'] ?? null,
            'algorithm_version' => $algorithmVersion,
            'configuration_version' => $configurationVersion,
            'request_id' => $requestId,
            'calculated_at' => now(),
            'snapshot' => [
                'decision_uuid' => $snapshot->decision_uuid,
                'fastapi_skill_gap' => $result['fastapi_skill_gap'] ?? $result,
                'fastapi_result' => $result,
                'request_id' => $requestId,
            ],
        ]);
    }

    /**
     * @return list<SkillGapResult>
     */
    private function persistSkillGaps(DecisionSnapshot $snapshot, array $skillGap): array
    {
        $results = [];

        foreach ($skillGap['skill_results'] as $skillResult) {
            $results[] = SkillGapResult::create([
                'decision_snapshot_id' => $snapshot->id,
                'skill_id' => (int) $skillResult['skill_id'],
                'current_level' => (float) $skillResult['current_level'],
                'required_level' => (float) $skillResult['required_level'],
                'gap' => (float) $skillResult['gap'],
                'match_score' => isset($skillResult['match_score']) && is_numeric($skillResult['match_score'])
                    ? (float) $skillResult['match_score']
                    : null,
                'importance_weight' => (float) $skillResult['importance_weight'],
                'is_critical' => (bool) $skillResult['is_critical'],
                'confidence' => isset($skillResult['confidence']) && is_numeric($skillResult['confidence'])
                    ? (float) $skillResult['confidence']
                    : null,
                'status' => (string) $skillResult['status'],
                'explanation' => $skillResult['explanation'] ?? null,
            ]);
        }

        return $results;
    }

    private function persistRoadmap(
        DecisionSnapshot $snapshot,
        array $roadmap,
        array $skillNameById,
        string $algorithmVersion,
        string $configurationVersion,
        string $requestId,
    ): Roadmap {
        return DB::transaction(function () use (
            $snapshot,
            $roadmap,
            $skillNameById,
            $algorithmVersion,
            $configurationVersion,
            $requestId,
        ) {
            // Historical versioning: only ONE active roadmap per
            // (learner, role) — the previous one is superseded, never
            // deleted or updated; its actions remain accessible.
            Roadmap::query()
                ->where('student_profile_id', $snapshot->student_profile_id)
                ->where('career_role_id', $snapshot->career_role_id)
                ->where('status', Roadmap::STATUS_ACTIVE)
                ->update(['status' => Roadmap::STATUS_SUPERSEDED]);

            /*
             * Roadmap version semantics (US-INT-01 / Roadmap v1): Laravel
             * owns the persisted roadmap version AND status — the version is
             * always the highest existing version for this (learner, role)
             * plus one, so historical roadmaps can never collapse onto the
             * same version, and the previous active roadmap is superseded
             * above. FastAPI is NOT required to send `roadmap_version` /
             * `status`: a stateless service cannot know the learner's
             * history, so those values are never taken from the response.
             */
            $nextVersion = ((int) Roadmap::query()
                ->where('student_profile_id', $snapshot->student_profile_id)
                ->where('career_role_id', $snapshot->career_role_id)
                ->max('version')) + 1;

            $model = Roadmap::create([
                'student_profile_id' => $snapshot->student_profile_id,
                'career_role_id' => $snapshot->career_role_id,
                'career_role_version' => $snapshot->career_role_version,
                'decision_snapshot_id' => $snapshot->id,
                'version' => $nextVersion,
                'status' => Roadmap::STATUS_ACTIVE,
                'generated_at' => now(),
                /*
                 * Ownership: FastAPI owns the roadmap algorithm/configuration
                 * versions and echoes them on its OWN roadmap response — they
                 * are NOT taken from the skill-gap response, which describes a
                 * different calculation and may legitimately report different
                 * values. The skill-gap-derived values remain a defensive
                 * fallback only.
                 */
                'algorithm_version' => $roadmap['algorithm_version'] ?? $algorithmVersion,
                'configuration_version' => $roadmap['configuration_version'] ?? $configurationVersion,
                'request_id' => $requestId,
                'explanation' => $roadmap['explanation'] ?? null,
                /*
                 * Roadmap-level totals and limitations are FastAPI-owned and
                 * are stored VERBATIM from the validated response: the total
                 * is never re-derived by summing the actions, the calendar
                 * duration is never recomputed from hours, and limitations
                 * are never dropped. `estimated_duration_weeks` is an
                 * integer or null (Roadmap v1).
                 */
                'estimated_total_hours' => isset($roadmap['estimated_total_hours'])
                    && is_numeric($roadmap['estimated_total_hours'])
                    ? (float) $roadmap['estimated_total_hours']
                    : null,
                'estimated_duration_weeks' => isset($roadmap['estimated_duration_weeks'])
                    && is_numeric($roadmap['estimated_duration_weeks'])
                    ? (int) $roadmap['estimated_duration_weeks']
                    : null,
                'limitations' => $roadmap['limitations'] ?? null,
            ]);

            $actionIdMap = $this->persistRoadmapActions($model, $roadmap, $skillNameById);

            $nextBestActionId = $this->resolveNextBestActionId($roadmap, $actionIdMap);

            if ($nextBestActionId !== null) {
                $model->forceFill(['next_best_action_id' => $nextBestActionId])->save();
            }

            return $model->fresh(['actions.prerequisites']);
        });
    }

    /**
     * Persist every roadmap action together with its FULL prerequisite set.
     *
     * Multiple prerequisites are stored in `roadmap_action_prerequisites`;
     * the legacy single column keeps the first prerequisite so existing
     * readers that only know about it do not regress.
     *
     * @param  array<string, string>  $skillNameById
     * @return array<string, RoadmapAction> FastAPI action_id => persisted action
     */
    private function persistRoadmapActions(Roadmap $roadmap, array $result, array $skillNameById): array
    {
        $orderIndex = 0;
        $actionIdMap = [];

        foreach ($result['phases'] as $phase) {
            foreach ($phase['actions'] as $action) {
                $targetSkillId = $action['target_skill_id'] ?? null;

                $prerequisiteSkillIds = $this->normalizePrerequisiteIds(
                    $action['prerequisite_skill_ids'] ?? null,
                );

                $model = RoadmapAction::create([
                    'roadmap_id' => $roadmap->id,
                    'phase' => $this->mapPhase($phase['phase']),
                    'type' => $this->mapType($action['action_type']),
                    'target_skill_id' => $targetSkillId !== null ? (int) $targetSkillId : null,
                    // Legacy single-prerequisite column: first id only, for
                    // backward compatibility. The relation below is the source
                    // of truth for the complete set.
                    'prerequisite_skill_id' => $prerequisiteSkillIds[0] ?? null,
                    'title' => (string) $action['title'],
                    'objective' => $action['objective'] ?? null,
                    'description' => $action['description'] ?? null,
                    'priority_score' => isset($action['priority_score']) && is_numeric($action['priority_score'])
                        ? (float) $action['priority_score']
                        : null,
                    'estimated_hours' => isset($action['estimated_hours']) && is_numeric($action['estimated_hours'])
                        ? (float) $action['estimated_hours']
                        : null,
                    /*
                     * Stored exactly as FastAPI returned it — the calendar
                     * duration in weeks is FastAPI's calculation (it depends
                     * on the learner's weekly availability) and is NEVER
                     * re-derived from hours here. The legacy
                     * `estimated_duration_hours` column is not written.
                     */
                    'estimated_duration_weeks' => isset($action['estimated_duration_weeks'])
                        && is_numeric($action['estimated_duration_weeks'])
                        ? (float) $action['estimated_duration_weeks']
                        : null,
                    'order_index' => $orderIndex++,
                    'fastapi_order' => isset($action['order_index']) && is_numeric($action['order_index'])
                        ? (int) $action['order_index']
                        : null,
                    'completion_criteria' => $action['completion_criteria'] ?? null,
                    'explanation' => $action['explanation'] ?? null,
                    /*
                     * Distinct from `prerequisite_skill_ids`: these are the
                     * skills CURRENTLY blocking the action. Stored verbatim
                     * as a JSON list (or null) and never merged into the
                     * prerequisite relation.
                     */
                    'blocking_prerequisite_skill_ids' => $action['blocking_prerequisite_skill_ids'] ?? null,
                ]);

                foreach ($prerequisiteSkillIds as $prerequisiteSkillId) {
                    RoadmapActionPrerequisite::create([
                        'roadmap_action_id' => $model->id,
                        'skill_id' => $prerequisiteSkillId,
                    ]);
                }

                if (isset($action['action_id'])) {
                    $actionIdMap[(string) $action['action_id']] = $model;
                }
            }
        }

        return $actionIdMap;
    }

    /**
     * Resolve FastAPI's next_best_action_id to the persisted action of the
     * SAME roadmap. The map is built only from this roadmap's actions, so a
     * cross-roadmap reference can never be stored; an id that does not map
     * to a returned action is left unset (the response validator already
     * rejects such a response before persistence).
     *
     * @param  array<string, mixed>  $roadmap
     * @param  array<string, RoadmapAction>  $actionIdMap
     */
    private function resolveNextBestActionId(array $roadmap, array $actionIdMap): ?int
    {
        $nextBestActionId = $roadmap['next_best_action_id'] ?? null;

        if ($nextBestActionId === null) {
            return null;
        }

        $action = $actionIdMap[(string) $nextBestActionId] ?? null;

        return $action instanceof RoadmapAction ? (int) $action->id : null;
    }

    /**
     * Explicit, non-silent prerequisite id list.
     *
     * null / absent means "no prerequisites". Anything else must already be
     * a list of integer ids: a non-array (e.g. "2,5,8") or a non-integer
     * element is a contract violation and fails loudly rather than being
     * silently discarded — the response validator enforces the same rule
     * before persistence, so this is a defensive guard, not a normalizer.
     * Duplicates are NOT collapsed here; the response validator rejects
     * them, and the unique index on roadmap_action_prerequisites would
     * reject them at the database level too.
     *
     * @return list<int>
     */
    private function normalizePrerequisiteIds(mixed $ids): array
    {
        if ($ids === null) {
            return [];
        }

        if (! is_array($ids)) {
            throw new IntelligenceException(
                'A roadmap action carries prerequisite_skill_ids in an invalid format.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['field' => 'prerequisite_skill_ids'],
            );
        }

        $normalized = [];

        foreach ($ids as $id) {
            if (! is_int($id)) {
                throw new IntelligenceException(
                    'A roadmap action carries a non-integer prerequisite skill id.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['field' => 'prerequisite_skill_ids'],
                );
            }

            $normalized[] = $id;
        }

        return $normalized;
    }

    private function mapPhase(string $phase): string
    {
        return match ($phase) {
            'foundations', 'core_skills', 'applied_practice', 'career_readiness' => $phase,
            default => 'core_skills',
        };
    }

    /**
     * Validate-and-return the action type. There is deliberately NO
     * fallback: an unknown type is a FastAPI contract violation and must
     * never be silently rewritten to "practice".
     */
    private function mapType(string $type): string
    {
        if (! in_array($type, RoadmapAction::TYPES, true)) {
            throw new IntelligenceException(
                'A roadmap action carries an unknown action type.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['action_type' => $type],
            );
        }

        return $type;
    }
}
