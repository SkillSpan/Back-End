<?php

namespace App\Services\Skills;

use App\Models\LearnerSkill;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\SkillEvidence;
use App\Models\StudentProfile;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calculates a skill's level and confidence from available evidence.
 *
 * Skill Level:
 *   - Same-source evidence is aggregated using:
 *
 *       SUM(normalized_value × recency_factor)
 *       -------------------------------------
 *              SUM(recency_factor)
 *
 *   - Source weight is applied once after same-source aggregation.
 *
 * Confidence:
 *   - Duplicate evidence identities are removed first.
 *   - Independent evidence records from the same source are aggregated using:
 *
 *       source_support =
 *           SUM(verification_factor × recency_factor)
 *           ----------------------------------------
 *                COUNT(distinct evidence records)
 *
 *   - Source weight is applied once:
 *
 *       source_contribution = source_weight × source_support
 *
 * Recency:
 *   - Calculated dynamically during every recalculate().
 *   - Based on calendar months from evidence_date to evaluation date.
 *
 * Recency version:
 *   evidence-recency-v1
 */
class SkillEvaluationService
{
    private const RECENCY_VERSION = 'evidence-recency-v1';

    private const ALGORITHM_VERSION = 'skill-confidence-v1';

    public function recalculate(
        StudentProfile $studentProfile,
        Skill $skill
    ): SkillEvaluation {
        $sourceWeights = config('services.evidence.source_weights', []);

        $pendingVerifiedFactor = (float) config(
            'services.evidence.pending_verified_factor',
            0.50
        );

        /*
         * One fixed evaluation timestamp is used for the whole calculation.
         *
         * This is important because every evidence record in the same
         * calculation must use the same "assessment date".
         */
        $evaluationDate = now();

        $evidenceBySource = SkillEvidence::where(
            'student_profile_id',
            $studentProfile->id
        )
            ->where('skill_id', $skill->id)
            ->get()
            ->groupBy('source');

        $levelNumerator = 0.0;
        $levelDenominator = 0.0;

        $confidenceNumerator = 0.0;
        $confidenceDenominator = 0.0;

        $contributions = [];

        foreach ($sourceWeights as $source => $weight) {
            $weight = (float) $weight;

            /*
             * Confidence denominator always contains all configured
             * source weights.
             *
             * Missing sources therefore reduce confidence.
             */
            $confidenceDenominator += $weight;

            $records = $evidenceBySource->get($source, collect());

            if ($records->isEmpty()) {
                $contributions[$source] = [
                    'weight' => $weight,
                    'records_count' => 0,
                    'distinct_records_count' => 0,
                    'included_in_level' => false,
                    'source_support' => 0.0,
                    'source_contribution' => 0.0,
                    'records' => [],
                ];

                continue;
            }

            /*
             * ---------------------------------------------------------
             * 1. REMOVE DUPLICATES
             * ---------------------------------------------------------
             *
             * Duplicate identity:
             *
             *   source_record_type + source_record_id
             *
             * If canonical identity is unavailable:
             *
             *   reference
             *
             * When duplicates exist, keep the newest evidence record.
             */
            $distinctRecords = $this->deduplicateEvidenceRecords($records);

            /*
             * ---------------------------------------------------------
             * 2. CALCULATE RECENCY DYNAMICALLY
             * ---------------------------------------------------------
             *
             * Never rely on the persisted recency_factor.
             *
             * The value is calculated from evidence_date to the single
             * evaluation timestamp.
             */
            $distinctRecords->each(function (SkillEvidence $evidence) use (
                $evaluationDate
            ) {
                $evidence->calculated_recency_factor =
                    $this->calculateRecencyFactor(
                        Carbon::parse($evidence->evidence_date),
                        $evaluationDate
                    );
            });

            /*
             * ---------------------------------------------------------
             * 3. SKILL LEVEL
             * ---------------------------------------------------------
             *
             * Only verified evidence contributes to Skill Level.
             *
             * Same-source aggregation:
             *
             * SUM(normalized_value × recency_factor)
             * -------------------------------------
             *       SUM(recency_factor)
             *
             * Then apply source weight ONCE.
             */
            $verifiedRecords = $distinctRecords->filter(
                fn(SkillEvidence $evidence) =>
                $evidence->verification_status === 'verified'
            );

            $includedInLevel = false;
            $aggregatedValue = null;

            if ($verifiedRecords->isNotEmpty()) {
                $recencySum = (float) $verifiedRecords->sum(
                    'calculated_recency_factor'
                );

                if ($recencySum > 0) {
                    $aggregatedValue =
                        $verifiedRecords->sum(
                            fn(SkillEvidence $evidence) =>
                            (float) $evidence->normalized_value
                                * (float) $evidence->calculated_recency_factor
                        )
                        / $recencySum;
                } else {
                    $aggregatedValue =
                        (float) $verifiedRecords->avg('normalized_value');
                }

                $levelNumerator += $aggregatedValue * $weight;
                $levelDenominator += $weight;

                $includedInLevel = true;
            }

            /*
             * ---------------------------------------------------------
             * 4. CONFIDENCE
             * ---------------------------------------------------------
             *
             * IMPORTANT:
             *
             * We DO NOT use "latest record only" anymore.
             *
             * Every independent evidence record contributes:
             *
             *   verification_factor × recency_factor
             *
             * Then:
             *
             *   source_support =
             *       SUM(record_support)
             *       ------------------
             *       COUNT(records)
             *
             * Finally:
             *
             *   source_contribution =
             *       source_weight × source_support
             *
             * This keeps the source weight applied only once.
             */
            $recordSupports = $distinctRecords->map(
                function (SkillEvidence $evidence) use (
                    $pendingVerifiedFactor
                ) {
                    $verificationFactor =
                        $this->verificationFactor(
                            $evidence->verification_status,
                            $pendingVerifiedFactor
                        );

                    $recencyFactor =
                        (float) $evidence->calculated_recency_factor;

                    return [
                        'evidence_id' => $evidence->id,
                        'verification_status' =>
                        $evidence->verification_status,
                        'verification_factor' => $verificationFactor,
                        'recency_factor' => $recencyFactor,
                        'record_support' =>
                        $verificationFactor * $recencyFactor,
                        'evidence_date' => $evidence->evidence_date,
                        'source_record_type' =>
                        $evidence->source_record_type,
                        'source_record_id' =>
                        $evidence->source_record_id,
                        'reference' =>
                        $evidence->reference,
                    ];
                }
            )->values();

            $recordCount = $recordSupports->count();

            $sourceSupport = $recordCount > 0
                ? (float) $recordSupports->sum('record_support')
                / $recordCount
                : 0.0;

            $sourceContribution = $weight * $sourceSupport;

            $confidenceNumerator += $sourceContribution;

            /*
             * Snapshot contains enough information to understand
             * exactly how this source contributed to the decision.
             */
            $contributions[$source] = [
                'weight' => $weight,

                'records_count' => $records->count(),

                'distinct_records_count' => $recordCount,

                'included_in_level' => $includedInLevel,

                'aggregated_value' => $aggregatedValue,

                'source_support' => round($sourceSupport, 6),

                'source_contribution' =>
                round($sourceContribution, 6),

                'recency_version' => self::RECENCY_VERSION,

                'records' => $recordSupports->all(),
            ];
        }

        /*
         * ---------------------------------------------------------
         * 5. FINAL SKILL LEVEL
         * ---------------------------------------------------------
         */
        $level = $levelDenominator > 0
            ? round(
                $levelNumerator / $levelDenominator,
                2
            )
            : 0.00;

        $level = max(
            0.00,
            min(5.00, $level)
        );

        /*
         * ---------------------------------------------------------
         * 6. FINAL CONFIDENCE
         * ---------------------------------------------------------
         */
        $confidence = $confidenceDenominator > 0
            ? round(
                100 * $confidenceNumerator / $confidenceDenominator,
                2
            )
            : 0.00;

        $confidence = max(
            0.00,
            min(100.00, $confidence)
        );

        /*
         * One algorithm version for this calculation.
         *
         * Recency has its own version because it is an independent
         * configurable decision component.
         */
        $algorithmVersion = self::ALGORITHM_VERSION;

        /*
         * Snapshot metadata.
         *
         * This makes old evaluations traceable and reproducible.
         */
        $snapshot = [
            'algorithm_version' => $algorithmVersion,

            'recency_version' => self::RECENCY_VERSION,

            'calculated_at' => $evaluationDate->toIso8601String(),

            'pending_verified_factor' => $pendingVerifiedFactor,

            'source_weights' => $sourceWeights,

            'sources' => $contributions,
        ];

        return DB::transaction(function () use (
            $studentProfile,
            $skill,
            $level,
            $confidence,
            $snapshot,
            $algorithmVersion,
            $evaluationDate
        ) {
            /*
             * skill_evaluations is the authoritative historical record.
             */
            $evaluation = SkillEvaluation::create([
                'student_profile_id' => $studentProfile->id,

                'skill_id' => $skill->id,

                'level' => $level,

                'confidence' => $confidence,

                'algorithm_version' => $algorithmVersion,

                'calculated_at' => $evaluationDate,

                'snapshot' => $snapshot,
            ]);

            /*
             * learner_skills is the current read projection.
             */
            LearnerSkill::updateOrCreate(
                [
                    'learner_id' => $studentProfile->user_id,
                    'skill_id' => $skill->id,
                ],
                [
                    'level' => $level,

                    'confidence_score' => $confidence,

                    'source_type' => 'evidence_aggregate',

                    'algorithm_version' => $algorithmVersion,

                    'calculated_at' => $evaluationDate,

                    'source_contributions' =>
                    $snapshot['sources'],

                    'latest_skill_evaluation_id' =>
                    $evaluation->id,
                ]
            );

            return $evaluation;
        });
    }

    /**
     * Remove duplicate evidence records.
     *
     * Duplicate policy:
     *
     * 1. If source_record_type + source_record_id exist,
     *    they form the canonical identity.
     *
     * 2. If canonical identity is unavailable, reference is used.
     *
     * 3. When duplicates exist, the newest record is retained.
     */
    private function deduplicateEvidenceRecords(
        Collection $records
    ): Collection {
        $groups = $records->groupBy(
            function (SkillEvidence $evidence) {
                /*
                 * Canonical identity exists.
                 */
                if (
                    $evidence->source_record_type !== null
                    && $evidence->source_record_id !== null
                ) {
                    return 'canonical:'
                        . $evidence->source_record_type
                        . ':'
                        . $evidence->source_record_id;
                }

                /*
                 * No canonical identity.
                 * Use reference if available.
                 */
                if ($evidence->reference !== null) {
                    return 'reference:'
                        . (string) $evidence->reference;
                }

                /*
                 * No identity information available.
                 *
                 * Treat each record as independent.
                 */
                return 'evidence:' . $evidence->id;
            }
        );

        return $groups
            ->map(function (Collection $duplicates) {
                /*
                 * Newest evidence_date wins.
                 *
                 * ID is used as a deterministic tie-breaker when
                 * two records have the same evidence_date.
                 */
                return $duplicates
                    ->sortByDesc(
                        fn(SkillEvidence $evidence) =>
                        Carbon::parse($evidence->evidence_date)
                            ->timestamp
                    )
                    ->sortByDesc('id')
                    ->first();
            })
            ->values();
    }

    /**
     * Convert verification status into the numerical verification factor
     * used by Confidence.
     */
    private function verificationFactor(
        ?string $status,
        float $pendingVerifiedFactor
    ): float {
        return match ($status) {
            'verified' => 1.0,

            'pending' => $pendingVerifiedFactor,

            default => 0.0,
        };
    }

    /**
     * Calculate evidence recency according to:
     *
     * 0-6 months       => 1.00
     * >6-12 months     => 0.90
     * >12-24 months    => 0.75
     * >24 months       => 0.60
     *
     * Calendar months are used instead of approximating months as
     * a fixed number of days.
     *
     * Exact 6/12/24 month boundaries belong to the preceding bucket.
     */
    private function calculateRecencyFactor(
        Carbon $evidenceDate,
        Carbon $evaluationDate
    ): float {
        $recencyConfig = config(
            'services.evidence.recency',
            []
        );

        $sixMonthsFactor = (float) (
            $recencyConfig['six_months'] ?? 1.00
        );

        $twelveMonthsFactor = (float) (
            $recencyConfig['twelve_months'] ?? 0.90
        );

        $twentyFourMonthsFactor = (float) (
            $recencyConfig['twenty_four_months'] ?? 0.75
        );

        $olderFactor = (float) (
            $recencyConfig['older'] ?? 0.60
        );

        if (
            $evaluationDate->lessThanOrEqualTo(
                $evidenceDate->copy()->addMonths(6)
            )
        ) {
            return $sixMonthsFactor;
        }

        if (
            $evaluationDate->lessThanOrEqualTo(
                $evidenceDate->copy()->addMonths(12)
            )
        ) {
            return $twelveMonthsFactor;
        }

        if (
            $evaluationDate->lessThanOrEqualTo(
                $evidenceDate->copy()->addMonths(24)
            )
        ) {
            return $twentyFourMonthsFactor;
        }

        return $olderFactor;
    }
}
