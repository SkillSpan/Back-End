<?php

namespace App\Services\Intelligence;

use App\Models\DecisionSnapshot;
use App\Models\ReadinessResult;
use App\Models\Roadmap;
use App\Models\RoadmapAction;
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
            'critical_cap_applied' => (bool) $result['critical_skill_cap_applied'],
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
             * Roadmap version semantics (US-INT-01): Laravel owns the
             * persisted roadmap version — it is always the highest
             * existing version for this (learner, role) plus one, so
             * historical roadmaps can never collapse onto the same
             * version. The `roadmap_version` returned by FastAPI stays
             * part of the contract and is validated by the response
             * validator, but it is NOT used as the persisted version:
             * a stateless service cannot know the learner's history.
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
                'algorithm_version' => $algorithmVersion,
                'configuration_version' => $configurationVersion,
                'request_id' => $requestId,
                'explanation' => $roadmap['explanation'] ?? null,
            ]);

            $this->persistRoadmapActions($model, $roadmap, $skillNameById);

            return $model->fresh(['actions']);
        });
    }

    /**
     * @param  array<string, string>  $skillNameById
     */
    private function persistRoadmapActions(Roadmap $roadmap, array $result, array $skillNameById): void
    {
        $orderIndex = 0;

        foreach ($result['phases'] as $phase) {
            foreach ($phase['actions'] as $action) {
                $targetSkillId = $action['target_skill_id'] ?? null;

                RoadmapAction::create([
                    'roadmap_id' => $roadmap->id,
                    'phase' => $this->mapPhase($phase['phase']),
                    'type' => $this->mapType($action['action_type']),
                    'target_skill_id' => $targetSkillId !== null ? (int) $targetSkillId : null,
                    'prerequisite_skill_id' => isset($action['prerequisite_skill_ids'][0])
                        ? (int) $action['prerequisite_skill_ids'][0]
                        : null,
                    'title' => (string) $action['title'],
                    'objective' => $action['objective'] ?? null,
                    'description' => $action['description'] ?? null,
                    'priority_score' => isset($action['priority_score']) && is_numeric($action['priority_score'])
                        ? (float) $action['priority_score']
                        : null,
                    'estimated_hours' => isset($action['estimated_hours']) && is_numeric($action['estimated_hours'])
                        ? (float) $action['estimated_hours']
                        : null,
                    'estimated_duration_hours' => isset($action['estimated_duration_hours'])
                        && is_numeric($action['estimated_duration_hours'])
                        ? (float) $action['estimated_duration_hours']
                        : null,
                    'order_index' => $orderIndex++,
                    'fastapi_order' => isset($action['order_index']) && is_numeric($action['order_index'])
                        ? (int) $action['order_index']
                        : null,
                    'completion_criteria' => $action['completion_criteria'] ?? null,
                    'explanation' => $action['explanation'] ?? null,
                ]);
            }
        }
    }

    private function mapPhase(string $phase): string
    {
        return match ($phase) {
            'foundations', 'core_skills', 'applied_practice', 'career_readiness' => $phase,
            default => 'core_skills',
        };
    }

    private function mapType(string $type): string
    {
        return match ($type) {
            'assessment', 'resource', 'practice', 'simulated_project', 'real_project' => $type,
            default => 'practice',
        };
    }
}
