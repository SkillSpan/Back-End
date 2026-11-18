<?php

namespace App\Services\Readiness;

use App\Models\StudentProfile;
use Illuminate\Support\Collection;

class CompositeReadinessInputs
{
    public function __construct(
        private readonly PracticalExperienceService $experience,
        private readonly ProfileCompletenessService $profile,
    ) {}

    public function build(StudentProfile $profile, Collection $evaluations): array
    {
        $experience = $this->experience->calculate($profile);
        $completeness = $this->profile->calculate($profile);
        // Baseline confidence is answer coverage, not evidence quality. Only the
        // canonical evidence aggregator's versioned records qualify here.
        $latest = $evaluations->sortByDesc('id')->unique('skill_id');
        $eligible = $latest->filter(fn ($evaluation) => in_array($evaluation->algorithm_version, ['skill-confidence-v1', 'skill-confidence-v2-baseline'], true)
            && is_array($evaluation->snapshot['sources'] ?? null)
            && is_numeric($evaluation->confidence)
            && (float) $evaluation->confidence >= 0
            && (float) $evaluation->confidence <= 100
        );
        $reliability = $latest->isNotEmpty() && $eligible->count() === $latest->count()
            ? round((float) $eligible->avg('confidence'), 2)
            : null;

        return [
            'practical_experience' => $experience['score'],
            'assessment_reliability' => $reliability,
            'profile_completeness' => $completeness['total_fields'] > 0 ? $completeness['score'] : null,
            'provenance' => [
                'practical_experience' => $experience,
                'profile_completeness' => $completeness,
                'assessment_reliability' => [
                    'algorithm_version' => 'evidence-quality-mean-v1',
                    'evaluation_ids' => $latest->pluck('id')->values()->all(),
                    'score' => $reliability,
                    'unavailable_reason' => $reliability === null
                        ? 'Canonical evidence confidence is required for every role skill; answer coverage is excluded.' : null,
                ],
            ],
        ];
    }
}
