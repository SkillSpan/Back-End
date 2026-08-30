<?php

namespace App\Services\Baseline;

use App\Exceptions\BaselineAssessmentException;
use App\Models\BaselineAssessment;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\SkillEvidence;
use App\Models\StudentProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BaselineAssessmentService
{
    public const ASSESSMENT_TYPE = 'baseline';

    public function __construct(
        private readonly BaselineDataScienceClient $dataScienceClient,
    ) {}

    public function start(
        StudentProfile $studentProfile,
        string $requestId
    ): BaselineAssessment {
        $version = $this->activeVersion();

        $exists = BaselineAssessment::query()
            ->where('student_profile_id', $studentProfile->id)
            ->where('assessment_type', self::ASSESSMENT_TYPE)
            ->where('assessment_version', $version)
            ->exists();

        if ($exists) {
            throw new BaselineAssessmentException(
                'A baseline assessment for this version already exists for this learner.',
                409,
                'ASSESSMENT_ALREADY_EXISTS',
                ['assessment_version' => $version],
            );
        }

        return BaselineAssessment::create([
            'student_profile_id' => $studentProfile->id,
            'assessment_type' => self::ASSESSMENT_TYPE,
            'assessment_version' => $version,
            'status' => 'in_progress',
            'progress' => [],
            'responses' => null,
            'result' => null,
            'normalized_skills' => null,
            'completed_at' => null,
        ]);
    }

    public function saveProgress(
        BaselineAssessment $assessment,
        ?array $progress,
        ?array $responses,
        string $requestId
    ): BaselineAssessment {
        $this->ensureNotCompleted($assessment);

        $data = [];

        if ($progress !== null) {
            $data['progress'] = $progress;
        }

        if ($responses !== null) {
            $data['responses'] = $responses;
        }

        if ($data === []) {
            return $assessment;
        }

        $assessment->update($data);

        return $assessment->fresh();
    }

    public function submit(
        BaselineAssessment $assessment,
        array $responses,
        string $requestId
    ): BaselineAssessment {
        $this->ensureNotCompleted($assessment);

        if ($responses === []) {
            throw new BaselineAssessmentException(
                'The baseline assessment responses cannot be empty.',
                422,
                'ASSESSMENT_RESPONSES_EMPTY',
            );
        }

        $serviceResult = $this->dataScienceClient->compute(
            $assessment->studentProfile,
            $assessment->assessment_version,
            $responses,
            $requestId,
        );

        $normalizedSkills = $this->normalizedSkills($serviceResult);

        $algorithmVersion = $this->extractAlgorithmVersion($serviceResult, $assessment);

        return DB::transaction(function () use (
            $assessment,
            $responses,
            $serviceResult,
            $normalizedSkills,
            $algorithmVersion,
            $requestId,
        ) {
            // Atomic status transition: only one submit may flip an
            // in_progress attempt to completed. The affected-row check
            // prevents a concurrent duplicate submit from writing twice.
            $completed = BaselineAssessment::query()
                ->whereKey($assessment->id)
                ->where('status', 'in_progress')
                ->update([
                    'status' => 'completed',
                    'responses' => $responses,
                    'result' => $serviceResult,
                    'normalized_skills' => $normalizedSkills,
                    'completed_at' => now(),
                ]);

            if ($completed !== 1) {
                throw new BaselineAssessmentException(
                    'This baseline assessment has already been completed.',
                    409,
                    'ASSESSMENT_ALREADY_COMPLETED',
                );
            }

            $this->writeSkillData(
                $assessment,
                $normalizedSkills,
                $algorithmVersion,
                $requestId,
            );

            return $assessment->fresh();
        });
    }

    private function normalizedSkills(array $serviceResult): array
    {
        $skills = $serviceResult['skills'] ?? null;

        if (! is_array($skills) || $skills === []) {
            throw new BaselineAssessmentException(
                'The intelligence response contains no skills.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        $normalized = [];

        foreach ($skills as $skill) {
            if (! is_array($skill)) {
                throw new BaselineAssessmentException(
                    'The intelligence response contains a malformed skill entry.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                );
            }

            $slug = $skill['slug'] ?? null;
            $level = $skill['level'] ?? null;

            if (! is_string($slug) || trim($slug) === '') {
                throw new BaselineAssessmentException(
                    'The intelligence response contains a skill without a slug.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                );
            }

            $activeSkill = Skill::query()
                ->where('slug', $slug)
                ->where('status', 'active')
                ->first();

            if (! $activeSkill) {
                throw new BaselineAssessmentException(
                    'The intelligence service referenced an unknown skill.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['slug' => $slug],
                );
            }

            if (! is_numeric($level) || (float) $level < 0 || (float) $level > 5) {
                throw new BaselineAssessmentException(
                    'The intelligence service returned an invalid skill level.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['slug' => $slug, 'level' => $level],
                );
            }

            $confidence = $skill['confidence'] ?? 0.0;

            if (! is_numeric($confidence) || (float) $confidence < 0 || (float) $confidence > 1) {
                throw new BaselineAssessmentException(
                    'The intelligence service returned an invalid skill confidence.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['slug' => $slug, 'confidence' => $confidence],
                );
            }

            $normalized[] = [
                'skill_id' => (int) $activeSkill->id,
                'slug' => $activeSkill->slug,
                'name' => $activeSkill->name,
                'level' => round((float) $level, 2),
                // Normalize to the 0-100 confidence scale used across the app.
                'confidence' => round((float) $confidence * 100, 2),
            ];
        }

        return $normalized;
    }

    private function extractAlgorithmVersion(
        array $serviceResult,
        BaselineAssessment $assessment
    ): string {
        $version = $serviceResult['algorithm_version'] ?? null;

        if (! is_string($version) || trim($version) === '') {
            throw new BaselineAssessmentException(
                'The intelligence response contains an invalid algorithm version.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        return $version;
    }

    private function writeSkillData(
        BaselineAssessment $assessment,
        array $normalizedSkills,
        string $algorithmVersion,
        string $requestId
    ): void {
        $studentProfileId = (int) $assessment->student_profile_id;
        $evidenceDate = now()->toDateString();

        foreach ($normalizedSkills as $skill) {
            SkillEvidence::create([
                'student_profile_id' => $studentProfileId,
                'skill_id' => $skill['skill_id'],
                'source' => 'assessment_test',
                'value' => $skill['level'],
                'normalized_value' => $skill['level'],
                'reference' => 'Baseline assessment '.$assessment->assessment_version,
                'evidence_date' => $evidenceDate,
                'verification_status' => 'verified',
                'reviewer_id' => null,
                'reviewer_notes' => 'Baseline assessment result from the intelligence service',
                'recency_factor' => 1.00,
                'source_record_type' => BaselineAssessment::class,
                'source_record_id' => $assessment->id,
            ]);

            SkillEvaluation::create([
                'student_profile_id' => $studentProfileId,
                'skill_id' => $skill['skill_id'],
                'level' => $skill['level'],
                'confidence' => $skill['confidence'],
                'algorithm_version' => $algorithmVersion,
                'calculated_at' => now(),
                'snapshot' => [
                    'assessment_id' => $assessment->id,
                    'assessment_version' => $assessment->assessment_version,
                    'source' => 'baseline_assessment',
                    'request_id' => $requestId,
                ],
            ]);
        }

        Log::info(
            'Baseline assessment completed and skill data initialized.',
            [
                'request_id' => $requestId,
                'assessment_id' => $assessment->id,
                'student_profile_id' => $studentProfileId,
                'skill_count' => count($normalizedSkills),
                'algorithm_version' => $algorithmVersion,
            ],
        );
    }

    private function ensureNotCompleted(BaselineAssessment $assessment): void
    {
        if ($assessment->isCompleted()) {
            throw new BaselineAssessmentException(
                'This baseline assessment has already been completed.',
                409,
                'ASSESSMENT_ALREADY_COMPLETED',
            );
        }
    }

    private function activeVersion(): string
    {
        return (string) config('services.data_science.baseline.version', 'v1.0');
    }
}
