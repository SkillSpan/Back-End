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

/**
 * Orchestrates the dynamic baseline assessment lifecycle.
 *
 *  start()  — given a learner's chosen career role, select questions
 *             (BaselineQuestionSelectionService), persist an immutable
 *             snapshot of the role + selected questions + skill mappings,
 *             and return an in-progress assessment.
 *  submit() — validate the submission against the frozen snapshot, call
 *             the intelligence service, validate that the returned skill
 *             evaluations belong to the approved snapshot, then persist
 *             SkillEvidence/SkillEvaluation transactionally and flip the
 *             assessment to completed.
 *
 * Everything that mutates assessment state runs inside a DB transaction
 * so a failed intelligence call or an invalid payload leaves the
 * assessment resumable (still `in_progress`) and writes nothing.
 */
class BaselineAssessmentService
{
    public const ASSESSMENT_TYPE = 'baseline';

    public function __construct(
        private readonly BaselineDataScienceClient $dataScienceClient,
        private readonly BaselineQuestionSelectionService $questionSelection,
    ) {}

    /**
     * Start a dynamic baseline assessment for a learner and a career role.
     *
     * The role, its required skills, and the selected questions are
     * frozen into baseline_question_snapshots at creation time so a later
     * question-bank or career-role edit can never retroactively change
     * what the learner was shown.
     */
    public function start(
        StudentProfile $studentProfile,
        CareerRole $careerRole,
        string $requestId
    ): BaselineAssessment {
        $version = $this->activeVersion();

        // Role access + version are validated before any write.
        if ($careerRole->status !== 'approved') {
            throw new BaselineAssessmentException(
                'The selected career role is not approved for baseline assessment.',
                422,
                'CAREER_ROLE_NOT_APPROVED',
                ['career_role_id' => $careerRole->id, 'status' => $careerRole->status],
            );
        }

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

        // Select before opening the transaction: selection failure (e.g.
        // insufficient coverage) must not leave a half-written assessment.
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

        // Question membership, duplicates, answer format and allowed
        // options — validated against the frozen snapshot, never against
        // the live (mutable) question bank.
        $this->questionSelection->validateResponsesAgainstSnapshot($snapshots, $responses);
        $this->questionSelection->validateCompleteness($snapshots, $responses);

        $allowedSkillIds = $snapshots
            ->pluck('skill_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $metadata = $assessment->snapshot_metadata ?? [];

        $serviceResult = $this->dataScienceClient->compute(
            $assessment->studentProfile,
            $assessment->assessment_version,
            $responses,
            $requestId,
            $assessment->career_role_id !== null ? (int) $assessment->career_role_id : null,
            isset($metadata['career_role_version']) ? (int) $metadata['career_role_version'] : null,
            $snapshots->map(fn (BaselineQuestionSnapshot $s) => (string) $s->item->item_id)->values()->all(),
        );

        // Intelligence evaluations must belong to the role's approved
        // skill set — a hallucinated / out-of-scope skill is a hard error.
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

            return $assessment->fresh(['questionSnapshots.item']);
        });
    }

    /**
     * Freeze the role skill ↔ question mapping for this assessment.
     *
     * @param  Collection<int, array<string, mixed>>  $selectedQuestions
     */
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
                'importance_weight' => $question['importance_weight'],
                'is_critical' => $question['is_critical'],
            ]);
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $selectedQuestions
     */
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

    /**
     * @return Collection<int, BaselineQuestionSnapshot>
     */
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

            // Requirement 6: every returned evaluation must map to a skill
            // in the approved snapshot. This blocks the intelligence
            // service from injecting evaluations for skills the learner
            // was never assessed on.
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
                // Normalize to the 0-100 confidence scale used across the app.
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
            // Retry safety: a re-dispatch of the same assessment must not
            // create duplicate evidence/evaluation rows. The assessment is
            // the natural idempotency key (source_record).
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

        /*
         * US-INT-01 §24: an approved (service-verified) assessment
         * update triggers a queued intelligence recalculation. The
         * caller's transaction has committed by the time the queued
         * listener runs.
         */
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
}
