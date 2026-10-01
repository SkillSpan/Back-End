<?php

namespace App\Http\Resources;

use App\Models\DecisionSnapshot;
use App\Models\ReadinessResult;
use App\Models\Roadmap;
use App\Models\SkillGapResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * US-INT-01 §28 — exposes only permitted frontend data: the decision
 * identity + versions, readiness, skill gaps, and roadmap. The full
 * internal snapshot (payload, raw FastAPI responses) is NOT exposed.
 *
 * @property array{snapshot: DecisionSnapshot, readiness: ReadinessResult|null, skill_gaps: array<SkillGapResult>, roadmap: Roadmap|null} $resource
 */
class IntelligenceResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DecisionSnapshot $snapshot */
        $snapshot = $this->resource['snapshot'];

        /** @var ReadinessResult|null $readiness */
        $readiness = $this->resource['readiness'];

        /** @var list<SkillGapResult> $skillGaps */
        $skillGaps = $this->resource['skill_gaps'];

        /** @var Roadmap|null $roadmap */
        $roadmap = $this->resource['roadmap'];

        return [
            'decision_id' => $snapshot->decision_uuid,
            'request_id' => $snapshot->request_id,
            'status' => $snapshot->status,
            'role_id' => $snapshot->career_role_id,
            'role_version' => $snapshot->career_role_version,
            'algorithm_version' => $readiness?->algorithm_version,
            'configuration_version' => $readiness?->configuration_version,
            'calculated_at' => optional($snapshot->calculated_at)->toIso8601String(),

            'readiness' => $readiness === null ? null : [
                'score' => (float) $readiness->score,
                'skill_match_component' => $readiness->skill_match_component === null
                    ? null
                    : (float) $readiness->skill_match_component,
                'practical_experience_component' => $readiness->practical_experience_component === null
                    ? null
                    : (float) $readiness->practical_experience_component,
                'assessment_reliability_component' => $readiness->assessment_reliability_component === null
                    ? null
                    : (float) $readiness->assessment_reliability_component,
                'profile_completeness_component' => $readiness->profile_completeness_component === null
                    ? null
                    : (float) $readiness->profile_completeness_component,
                'critical_cap_applied' => (bool) $readiness->critical_cap_applied,
                'band' => $readiness->band,
            ],

            'skill_gaps' => Collection::make($skillGaps)
                ->map(fn (SkillGapResult $gap): array => [
                    'skill_id' => $gap->skill_id,
                    'skill_name' => $gap->skill?->name,
                    'current_level' => (float) $gap->current_level,
                    'required_level' => (float) $gap->required_level,
                    'gap' => (float) $gap->gap,
                    'match_score' => $gap->match_score === null ? null : (float) $gap->match_score,
                    'importance_weight' => (float) $gap->importance_weight,
                    'is_critical' => (bool) $gap->is_critical,
                    'confidence' => $gap->confidence === null ? null : (float) $gap->confidence,
                    'status' => $gap->status,
                    'explanation' => $gap->explanation,
                ])
                ->values()
                ->all(),

            'roadmap' => $roadmap === null ? null : [
                'id' => $roadmap->id,
                'roadmap_version' => $roadmap->version,
                'status' => $roadmap->status,
                /*
                 * The ROADMAP's own algorithm/configuration versions (owned
                 * by FastAPI and echoed on the roadmap response). These are
                 * deliberately NOT the top-level readiness/skill-gap
                 * versions above — the two calculations may report
                 * different versions, so they must never be mixed.
                 */
                'algorithm_version' => $roadmap->algorithm_version,
                'configuration_version' => $roadmap->configuration_version,
                'next_best_action_id' => $roadmap->next_best_action_id,
                'generated_at' => optional($roadmap->generated_at)->toIso8601String(),
                'explanation' => $roadmap->explanation,
                /*
                 * Roadmap-level totals (FastAPI-owned) and the limitations
                 * list. `estimated_duration_weeks` is an integer or null
                 * (null when the learner has no weekly availability); the
                 * limitations are surfaced verbatim, never dropped.
                 */
                'estimated_total_hours' => $roadmap->estimated_total_hours === null
                    ? null
                    : (float) $roadmap->estimated_total_hours,
                'estimated_duration_weeks' => $roadmap->estimated_duration_weeks === null
                    ? null
                    : (int) $roadmap->estimated_duration_weeks,
                'limitations' => $roadmap->limitations,
                'actions' => $roadmap->actions->map(fn ($action): array => [
                    'id' => $action->id,
                    'phase' => $action->phase,
                    'type' => $action->type,
                    'title' => $action->title,
                    'objective' => $action->objective,
                    'description' => $action->description,
                    'target_skill_id' => $action->target_skill_id,
                    // Legacy single prerequisite, kept for backward
                    // compatibility; `prerequisite_skill_ids` is the full set.
                    'prerequisite_skill_id' => $action->prerequisite_skill_id,
                    'prerequisite_skill_ids' => $action->prerequisites
                        ->pluck('id')
                        ->map(fn ($id): int => (int) $id)
                        ->values()
                        ->all(),
                    'priority_score' => $action->priority_score === null ? null : (float) $action->priority_score,
                    'estimated_hours' => $action->estimated_hours === null ? null : (float) $action->estimated_hours,
                    'estimated_duration_weeks' => $action->estimated_duration_weeks === null
                        ? null
                        : (float) $action->estimated_duration_weeks,
                    'order_index' => $action->order_index,
                    'completion_criteria' => $action->completion_criteria,
                    'explanation' => $action->explanation,
                    'status' => $action->status,
                ])->values()->all(),
            ],
        ];
    }
}
