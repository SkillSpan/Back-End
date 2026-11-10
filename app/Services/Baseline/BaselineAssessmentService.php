<?php

namespace App\Services\Baseline;

use App\Events\SkillDataChanged;
use App\Exceptions\BaselineAssessmentException;
use App\Models\BaselineAssessment;
use App\Models\BaselineQuestionSnapshot;
use App\Models\CareerRole;
use App\Models\Skill;
use App\Models\SkillEvaluation;
use App\Models\SkillEvidence;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BaselineAssessmentService
{
    public const ASSESSMENT_TYPE = 'baseline';

    public function __construct(
        private readonly BaselineDataScienceClient $dataScienceClient,
        private readonly BaselineQuestionSelectionService $questionSelection,
        private readonly BaselineIntelligencePayloadBuilder $payloadBuilder,
    ) {}

    public function start(
        StudentProfile $studentProfile,
        CareerRole $careerRole,
        string $requestId
    ): BaselineAssessment {
        $version = $this->activeVersion();

        if ($careerRole->status !== 'approved') {
            throw new BaselineAssessmentException(
                'The selected career role is not approved for baseline assessment.',
                422,
                'CAREER_ROLE_NOT_APPROVED',
                ['career_role_id' => $careerRole->id, 'status' => $careerRole->status],
            );
        }

        if (! $careerRole->is_active) {
            throw new BaselineAssessmentException(
                'The selected career role is not available for baseline assessment.',
                422,
                'CAREER_ROLE_INACTIVE',
                ['career_role_id' => $careerRole->id],
            );
        }

        $exists = BaselineAssessment::query()
            ->where('student_profile_id', $studentProfile->id)
            ->where('assessment_type', self::ASSESSMENT_TYPE)
            ->where('assessment_version', $version)
            ->where('career_role_id', $careerRole->id)
            ->exists();

        if ($exists) {
            throw new BaselineAssessmentException(
                'A baseline assessment for this role and version already exists for this learner.',
                409,
                'ASSESSMENT_ALREADY_EXISTS',
                [
                    'assessment_version' => $version,
                    'career_role_id' => $careerRole->id,
                ],
            );
        }

        $selectedQuestions = $this->questionSelection->select($careerRole, $version);

        if ($selectedQuestions->isEmpty()) {
            throw BaselineAssessmentException::careerRoleHasNoSkills(
                (int) $careerRole->id
            );
        }

        return DB::transaction(function () use (
            $studentProfile,
            $careerRole,
            $version,
            $selectedQuestions,
            $requestId,
        ) {
            $coverage = $this->buildCoverage($selectedQuestions);

            $this->recordPrimaryCareerRole($studentProfile, $careerRole);

            $assessment = BaselineAssessment::forceCreate([
                'student_profile_id' => $studentProfile->id,
                'career_role_id' => $careerRole->id,
                'assessment_type' => self::ASSESSMENT_TYPE,
                'assessment_version' => $version,
                'question_count' => $selectedQuestions->count(),
                'status' => 'in_progress',
                'progress' => [],
                'responses' => null,
                'result' => null,
                'normalized_skills' => null,
                'skill_coverage' => $coverage,
                'snapshot_metadata' => [
                    'career_role_id' => (int) $careerRole->id,
                    'career_role_slug' => $careerRole->slug,
                    'career_role_title' => $careerRole->title,
                    'career_role_version' => (int) $careerRole->version,
                    'assessment_version' => $version,
                    'question_count' => $selectedQuestions->count(),
                    'skill_count' => $coverage['skill_count'],
                    'critical_skill_count' => $coverage['critical_skill_count'],
                    'selection_strategy' => config('services.baseline_assessment.deterministic_selection', false)
                        ? 'deterministic'
                        : 'randomised',
                    'selected_at' => now()->toIso8601String(),
                    'request_id' => $requestId,
                ],
                'completed_at' => null,
            ]);

            $this->persistSnapshots($assessment, $careerRole, $selectedQuestions);

            Log::info('Baseline assessment started.', [
                'request_id' => $requestId,
                'assessment_id' => $assessment->id,
                'student_profile_id' => $studentProfile->id,
                'career_role_id' => $careerRole->id,
                'question_count' => $selectedQuestions->count(),
            ]);

            return $assessment->fresh(['questionSnapshots.item']);
        });
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

        return $assessment->fresh(['questionSnapshots.item']);
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

        $snapshots = $this->snapshotsFor($assessment);

        $this->questionSelection->validateResponsesAgainstSnapshot($snapshots, $responses);
        $this->questionSelection->validateCompleteness($snapshots, $responses);

        $allowedSkillIds = $snapshots
            ->pluck('skill_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $payload = $this->payloadBuilder->build($assessment, $snapshots, $responses);

        $serviceResult = $this->dataScienceClient->compute(
            $assessment->studentProfile,
            $assessment->assessment_version,
            $payload['questions'],
            $payload['responses'],
            $requestId,
            $assessment->career_role_id !== null ? (int) $assessment->career_role_id : null,
        );

        $normalizedSkills = $this->normalizedSkills($serviceResult, $allowedSkillIds);

        $algorithmVersion = $this->extractAlgorithmVersion($serviceResult);

        return DB::transaction(function () use (
            $assessment,
            $responses,
            $serviceResult,
            $normalizedSkills,
            $algorithmVersion,
            $requestId,
        ) {
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

            return $assessment->fresh(['questionSnapshots.item']);
        });
    }

    private function persistSnapshots(
        BaselineAssessment $assessment,
        CareerRole $careerRole,
        Collection $selectedQuestions
    ): void {
        foreach ($selectedQuestions as $question) {
            BaselineQuestionSnapshot::forceCreate([
                'baseline_assessment_id' => $assessment->id,
                'baseline_assessment_item_id' => $question['baseline_assessment_item_id'],
                'career_role_id' => $careerRole->id,
                'skill_id' => $question['skill_id'],
                'item_id' => $question['item_id'],
                'item_type' => $question['item_type'],
                'question_text' => $question['question_text'],
                'options' => $question['options'],
                'correct_answer' => $question['correct_answer'],
                'scoring_rule' => $question['scoring_rule'],
                'weight' => $question['weight'],
                'skill_slug' => $question['skill_slug'],
                'importance_weight' => $question['importance_weight'],
                'is_critical' => $question['is_critical'],
            ]);
        }
    }

    private function buildCoverage(Collection $selectedQuestions): array
    {
        $bySkill = $selectedQuestions->groupBy('skill_id')->map(
            fn (Collection $rows, $skillId) => [
                'skill_id' => (int) $skillId,
                'skill_slug' => $rows->first()['skill_slug'],
                'skill_name' => $rows->first()['skill_name'],
                'question_count' => $rows->count(),
                'importance_weight' => (float) $rows->first()['importance_weight'],
                'required_level' => (float) $rows->first()['required_level'],
                'is_critical' => (bool) $rows->first()['is_critical'],
                'prerequisite_slugs' => $rows->first()['prerequisite_slugs'],
                'covered' => true,
            ]
        )->values()->all();

        return [
            'total_questions' => $selectedQuestions->count(),
            'skill_count' => count($bySkill),
            'covered_skill_count' => count($bySkill),
            'critical_skill_count' => $selectedQuestions
                ->where('is_critical', true)
                ->pluck('skill_id')
                ->unique()
                ->count(),
            'skills' => $bySkill,
        ];
    }

    private function snapshotsFor(BaselineAssessment $assessment): Collection
    {
        return $assessment->questionSnapshots()
            ->with('item')
            ->get();
    }

    private function normalizedSkills(array $serviceResult, array $allowedSkillIds): array
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

            if (array_key_exists('skill_id', $skill) && $skill['skill_id'] !== null) {
                if (! is_numeric($skill['skill_id'])
                    || (int) $skill['skill_id'] !== (int) $activeSkill->id) {
                    throw new BaselineAssessmentException(
                        'The intelligence service returned a skill_id that does not match its slug.',
                        502,
                        'INTELLIGENCE_SKILL_MISMATCH',
                        [
                            'slug' => $slug,
                            'returned_skill_id' => $skill['skill_id'],
                            'expected_skill_id' => (int) $activeSkill->id,
                        ],
                    );
                }
            }

            if ($allowedSkillIds !== [] && ! in_array((int) $activeSkill->id, $allowedSkillIds, true)) {
                throw new BaselineAssessmentException(
                    'The intelligence service referenced a skill outside the approved assessment snapshot.',
                    502,
                    'INTELLIGENCE_SKILL_OUT_OF_SCOPE',
                    ['slug' => $slug, 'skill_id' => (int) $activeSkill->id],
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
                'confidence' => round((float) $confidence * 100, 2),
            ];
        }

        return $normalized;
    }

    private function extractAlgorithmVersion(array $serviceResult): string
    {
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
            $alreadyPersisted = SkillEvidence::query()
                ->where('source_record_type', BaselineAssessment::class)
                ->where('source_record_id', $assessment->id)
                ->where('skill_id', $skill['skill_id'])
                ->exists();

            if ($alreadyPersisted) {
                continue;
            }

            SkillEvidence::forceCreate([
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

            SkillEvaluation::forceCreate([
                'student_profile_id' => $studentProfileId,
                'skill_id' => $skill['skill_id'],
                'level' => $skill['level'],
                'confidence' => $skill['confidence'],
                'algorithm_version' => $algorithmVersion,
                'calculated_at' => now(),
                'snapshot' => [
                    'assessment_id' => $assessment->id,
                    'assessment_version' => $assessment->assessment_version,
                    'career_role_id' => $assessment->career_role_id,
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

        SkillDataChanged::dispatch(
            $assessment->studentProfile,
            'baseline_assessment_submit',
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

    private function recordPrimaryCareerRole(StudentProfile $studentProfile, CareerRole $careerRole): void
    {
        if ((int) $studentProfile->primary_career_role_id === (int) $careerRole->id) {
            return;
        }

        $studentProfile->forceFill(['primary_career_role_id' => $careerRole->id])->save();
    }
}
