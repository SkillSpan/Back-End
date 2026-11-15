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

class IntelligencePersistenceService
{
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
            Roadmap::query()
                ->where('student_profile_id', $snapshot->student_profile_id)
                ->where('career_role_id', $snapshot->career_role_id)
                ->where('status', Roadmap::STATUS_ACTIVE)
                ->update(['status' => Roadmap::STATUS_SUPERSEDED]);

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
                'algorithm_version' => $roadmap['algorithm_version'] ?? $algorithmVersion,
                'configuration_version' => $roadmap['configuration_version'] ?? $configurationVersion,
                'request_id' => $requestId,
                'explanation' => $roadmap['explanation'] ?? null,
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

    private function resolveNextBestActionId(array $roadmap, array $actionIdMap): ?int
    {
        $nextBestActionId = $roadmap['next_best_action_id'] ?? null;

        if ($nextBestActionId === null) {
            return null;
        }

        $action = $actionIdMap[(string) $nextBestActionId] ?? null;

        return $action instanceof RoadmapAction ? (int) $action->id : null;
    }

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
        if (! array_key_exists($phase, RoadmapAction::PHASE_ORDER)) {
            throw new IntelligenceException(
                'A roadmap action carries an unknown phase.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['phase' => $phase],
            );
        }

        return $phase;
    }

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
