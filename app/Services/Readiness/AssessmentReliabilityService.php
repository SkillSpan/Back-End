<?php

namespace App\Services\Readiness;

use App\Models\BaselineAssessment;
use App\Models\StudentProfile;
use Illuminate\Support\Facades\Log;

class AssessmentReliabilityService
{
    private const ALGORITHM_VERSION = 'assessment-reliability-v1';

    public function calculate(StudentProfile $studentProfile): array
    {
        $assessments = BaselineAssessment::query()
            ->where('student_profile_id', $studentProfile->id)
            ->where('status', 'completed')
            ->whereNotNull('normalized_skills')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get();

        foreach ($assessments as $assessment) {
            $skills = $assessment->normalized_skills;
            if (!is_array($skills) || $skills === []) {
                continue;
            }

            $confidenceValues = [];
            foreach ($skills as $skill) {
                if (!is_array($skill) || !isset($skill['confidence'])) {
                    continue;
                }
                $confidence = $skill['confidence'];
                // FIX: normalized_skills[].confidence is already stored on the
                // 0-100 scale (see BaselineAssessmentService::normalizedSkills(),
                // which multiplies the raw 0-1 FastAPI confidence by 100 before
                // persisting). The old `<= 1` bound rejected virtually every
                // real value and made this component return null on every call.
                if (is_numeric($confidence) && (float) $confidence >= 0 && (float) $confidence <= 100) {
                    $confidenceValues[] = (float) $confidence;
                }
            }

            if ($confidenceValues === []) {
                continue;
            }

            // FIX: values are already 0-100, so no extra "100 *" scaling here.
            $score = array_sum($confidenceValues) / count($confidenceValues);
            Log::info('Assessment Reliability v1 calculated.', [
                'student_profile_id' => $studentProfile->id,
                'assessment_id' => $assessment->id,
                'skill_count' => count($confidenceValues),
                'score' => $score,
            ]);

            return [
                'score' => round($score, 2),
                'assessment_id' => $assessment->id,
                'algorithm_version' => self::ALGORITHM_VERSION,
                'config_version' => config('readiness.assessment_reliability.config_version', 'assessment-reliability-config-v1'),
            ];
        }

        Log::info('No valid completed BaselineAssessment found for Assessment Reliability v1.', [
            'student_profile_id' => $studentProfile->id,
        ]);

        return [
            'score' => null,
            'assessment_id' => null,
            'algorithm_version' => self::ALGORITHM_VERSION,
            'config_version' => config('readiness.assessment_reliability.config_version', 'assessment-reliability-config-v1'),
        ];
    }
}
