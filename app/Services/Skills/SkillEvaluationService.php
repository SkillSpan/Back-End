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

            $distinctRecords = $this->deduplicateEvidenceRecords($records);

            $distinctRecords->each(function (SkillEvidence $evidence) use (
                $evaluationDate
            ) {
                $evidence->calculated_recency_factor =
                    $this->calculateRecencyFactor(
                        Carbon::parse($evidence->evidence_date),
                        $evaluationDate
                    );
            });

            $verifiedRecords = $distinctRecords->filter(
                fn (SkillEvidence $evidence) => $evidence->verification_status === 'verified'
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
                            fn (SkillEvidence $evidence) => (float) $evidence->normalized_value
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
                        'verification_status' => $evidence->verification_status,
                        'verification_factor' => $verificationFactor,
                        'recency_factor' => $recencyFactor,
                        'record_support' => $verificationFactor * $recencyFactor,
                        'evidence_date' => $evidence->evidence_date,
                        'source_record_type' => $evidence->source_record_type,
                        'source_record_id' => $evidence->source_record_id,
                        'reference' => $evidence->reference,
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

            $contributions[$source] = [
                'weight' => $weight,

                'records_count' => $records->count(),

                'distinct_records_count' => $recordCount,

                'included_in_level' => $includedInLevel,

                'aggregated_value' => $aggregatedValue,

                'source_support' => round($sourceSupport, 6),

                'source_contribution' => round($sourceContribution, 6),

                'recency_version' => self::RECENCY_VERSION,

                'records' => $recordSupports->all(),
            ];
        }

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

        $algorithmVersion = self::ALGORITHM_VERSION;

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
            $evaluation = SkillEvaluation::create([
                'student_profile_id' => $studentProfile->id,

                'skill_id' => $skill->id,

                'level' => $level,

                'confidence' => $confidence,

                'algorithm_version' => $algorithmVersion,

                'calculated_at' => $evaluationDate,

                'snapshot' => $snapshot,
            ]);

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

                    'source_contributions' => $snapshot['sources'],

                    'latest_skill_evaluation_id' => $evaluation->id,
                ]
            );

            return $evaluation;
        });
    }

    private function deduplicateEvidenceRecords(
        Collection $records
    ): Collection {
        $groups = $records->groupBy(
            function (SkillEvidence $evidence) {
                if (
                    $evidence->source_record_type !== null
                    && $evidence->source_record_id !== null
                ) {
                    return 'canonical:'
                        .$evidence->source_record_type
                        .':'
                        .$evidence->source_record_id;
                }

                if ($evidence->reference !== null) {
                    return 'reference:'
                        .(string) $evidence->reference;
                }

                return 'evidence:'.$evidence->id;
            }
        );

        return $groups
            ->map(function (Collection $duplicates) {
                return $duplicates
                    ->sortByDesc(
                        fn (SkillEvidence $evidence) => Carbon::parse($evidence->evidence_date)
                            ->timestamp
                    )
                    ->sortByDesc('id')
                    ->first();
            })
            ->values();
    }

    private function verificationFactor(
        ?string $status,
        float $pendingVerifiedFactor
    ): float {
        return match ($status) {
            'verified' => 1.0,

            'pending' => $pendingVerifiedFactor,

            'revision' => 0.0,

            default => 0.0,
        };
    }

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
