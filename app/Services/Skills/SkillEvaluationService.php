<?php

namespace App\Services\Skills;

use App\Models\LearnerSkill;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\SkillEvidence;
use App\Models\StudentProfile;
use Illuminate\Support\Facades\DB;

/**
 * Calculates a skill's level and confidence from all available evidence,
 * per SRS v1.1 Section 10.3 (Tables 48-50):
 *
 *   SkillLevel(s)  = SUM(value_i * weight_i) / SUM(weight_i for available valid evidence)
 *   Confidence(s)  = 100 * SUM(weight_i * verified_i * recency_i) / SUM(all configured weights)
 *
 * Writes the result to skill_evaluations (authoritative history) and
 * upserts learner_skills (current projection used by GET /skills/matrix),
 * linked via latest_skill_evaluation_id, in a single transaction.
 */
class SkillEvaluationService
{
    public function recalculate(StudentProfile $studentProfile, Skill $skill): SkillEvaluation
    {
        $sourceWeights = config('services.evidence.source_weights');
        $pendingVerifiedFactor = config('services.evidence.pending_verified_factor', 0.5);

        $evidenceBySource = SkillEvidence::where('student_profile_id', $studentProfile->id)
            ->where('skill_id', $skill->id)
            ->get()
            ->groupBy('source');

        $levelNumerator = 0.0;
        $levelDenominator = 0.0;
        $confidenceNumerator = 0.0;
        $confidenceDenominator = 0.0;
        $contributions = [];

        foreach ($sourceWeights as $source => $weight) {
            // Per Table 50 / SRS 10.3.2: the denominator is the sum of ALL
            // configured weights, whether or not this source has evidence —
            // missing sources reduce confidence rather than being ignored.
            $confidenceDenominator += $weight;

            $records = $evidenceBySource->get($source, collect());

            if ($records->isEmpty()) {
                $contributions[$source] = [
                    'weight' => $weight,
                    'records_count' => 0,
                    'included_in_level' => false,
                    'verified_i' => 0.0,
                ];

                continue;
            }

            // Multiple evidence records from the same source are aggregated
            // within the source first (recency-weighted), then the source
            // weight is applied once — agreed with Data Science so repeated
            // submissions from one source don't inflate its influence.
            $verifiedRecords = $records->where('verification_status', 'verified');

            $includedInLevel = false;

            if ($verifiedRecords->isNotEmpty()) {
                $recencySum = (float) $verifiedRecords->sum('recency_factor');

                $aggregatedValue = $recencySum > 0
                    ? $verifiedRecords->sum(fn ($e) => $e->normalized_value * $e->recency_factor) / $recencySum
                    : (float) $verifiedRecords->avg('normalized_value');

                $levelNumerator += $aggregatedValue * $weight;
                $levelDenominator += $weight;
                $includedInLevel = true;
            }

            // For confidence, use the most recent record in this source to
            // represent its verification state and recency.
            $latest = $records->sortByDesc('evidence_date')->first();

            $verifiedI = match ($latest->verification_status) {
                'verified' => 1.0,
                'pending' => $pendingVerifiedFactor,
                default => 0.0, // rejected, expired
            };

            $confidenceNumerator += $weight * $verifiedI * (float) $latest->recency_factor;

            $contributions[$source] = [
                'weight' => $weight,
                'records_count' => $records->count(),
                'included_in_level' => $includedInLevel,
                'verified_i' => $verifiedI,
                'recency_factor' => (float) $latest->recency_factor,
            ];
        }

        $level = $levelDenominator > 0
            ? round($levelNumerator / $levelDenominator, 2)
            : 0.00;
        $level = max(0.00, min(5.00, $level));

        $confidence = $confidenceDenominator > 0
            ? round(100 * $confidenceNumerator / $confidenceDenominator, 2)
            : 0.00;
        $confidence = max(0.00, min(100.00, $confidence));

        $algorithmVersion = 'evidence-srs-v1';

        return DB::transaction(function () use ($studentProfile, $skill, $level, $confidence, $contributions, $algorithmVersion) {
            $evaluation = SkillEvaluation::create([
                'student_profile_id' => $studentProfile->id,
                'skill_id' => $skill->id,
                'level' => $level,
                'confidence' => $confidence,
                'algorithm_version' => $algorithmVersion,
                'calculated_at' => now(),
                'snapshot' => $contributions,
            ]);

            // learner_skills is the read projection keyed by learner_id
            // (users.id), not student_profile_id — resolve it via the
            // profile's owning user.
            LearnerSkill::updateOrCreate(
                ['learner_id' => $studentProfile->user_id, 'skill_id' => $skill->id],
                [
                    'level' => $level,
                    'confidence_score' => $confidence,
                    'source_type' => 'evidence_aggregate',
                    'algorithm_version' => $algorithmVersion,
                    'calculated_at' => now(),
                    'source_contributions' => $contributions,
                    'latest_skill_evaluation_id' => $evaluation->id,
                ]
            );

            return $evaluation;
        });
    }
}
