<?php

namespace App\Services\Readiness;

use App\Exceptions\ReadinessException;
use App\Exceptions\ReadinessIntegrationException;
use App\Models\CareerRole;
use App\Models\DecisionSnapshot;
use App\Models\ReadinessResult;
use App\Models\SkillEvaluation;
use App\Models\SkillGapResult;
use App\Models\StudentProfile;
use App\Services\Intelligence\DecisionSnapshotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReadinessService
{
    public function __construct(
        private readonly DataScienceClient $dataScienceClient,
        private readonly ReadinessPayloadBuilder $payloadBuilder,
        private readonly DecisionSnapshotService $decisionSnapshotService,
        private readonly PracticalExperienceService $practicalExperienceService,
        private readonly AssessmentReliabilityService $assessmentReliabilityService,
        private readonly ProfileCompletenessService $profileCompletenessService,
    ) {}

    public function calculate(
        StudentProfile $studentProfile,
        ?int $careerRoleId,
        string $requestId
    ): ReadinessResult {
        $careerRoleId ??= $studentProfile->primary_career_role_id;

        if (! $careerRoleId) {
            throw new ReadinessException(
                'A career role is required for readiness calculation.',
                422,
                'CAREER_ROLE_REQUIRED',
            );
        }

        $careerRole = CareerRole::query()
            ->whereKey($careerRoleId)
            ->where('status', 'approved')
            ->with('roleSkills.skill')
            ->first();

        if (! $careerRole) {
            $exists = CareerRole::query()
                ->whereKey($careerRoleId)
                ->exists();

            if ($exists) {
                throw new ReadinessException(
                    'The selected career role is not approved for readiness calculation.',
                    422,
                    'CAREER_ROLE_NOT_APPROVED',
                );
            }

            throw new ReadinessException(
                'The selected career role does not exist.',
                404,
                'CAREER_ROLE_NOT_FOUND',
                ['career_role_id' => $careerRoleId],
            );
        }

        $roleSkills = $careerRole->roleSkills;

        if ($roleSkills->isEmpty()) {
            throw new ReadinessException(
                'The selected career role has no required skills.',
                422,
                'CAREER_ROLE_NO_SKILLS',
            );
        }

        $latestEvaluationsBySkillId = SkillEvaluation::query()
            ->where('student_profile_id', $studentProfile->id)
            ->whereIn('skill_id', $roleSkills->pluck('skill_id'))
            ->orderByDesc('calculated_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy(
                fn (SkillEvaluation $evaluation) => (int) $evaluation->skill_id
            );

        $missingSkillIds = [];

        foreach ($roleSkills as $roleSkill) {
            $latestEvaluation = $latestEvaluationsBySkillId
                ->get((int) $roleSkill->skill_id)
                ?->first();

            $roleSkill->setAttribute(
                'latest_evaluation',
                $latestEvaluation
            );

            if ($latestEvaluation === null) {
                $missingSkillIds[] = (int) $roleSkill->skill_id;
            }
        }

        if ($missingSkillIds !== []) {
            throw new ReadinessIntegrationException(
                'The learner does not have evaluations for all required skills.',
                422,
                'ASSESSMENT_INCOMPLETE',
                ['missing_skill_ids' => $missingSkillIds],
            );
        }

        $payload = $this->payloadBuilder->build(
            $studentProfile,
            $careerRole,
            $roleSkills,
        );

        /*
         * US-INT-01 §10: resolve the active algorithm configuration.
         * An explicit error is returned when none is active; no fabricated
         * default configuration is used.
         */
        $configuration = $this->decisionSnapshotService->resolveConfiguration();
        $configurationVersion = 'config-v'.$configuration->version;

        /*
         * ADR-001 — the composite STRUCTURE version, recorded alongside the
         * numeric configuration version so a historical score stays
         * replayable. Resolved from configuration, never fabricated.
         */
        $compositeVersion = (string) config(
            'readiness.composite_algorithm_version',
            'composite-readiness-v1'
        );

        /*
         * US-INT-01 §6: capture the validated input state BEFORE the
         * FastAPI call. The snapshot starts as pending and is marked
         * succeeded only after the FastAPI response is validated and
         * persistence completes atomically.
         */
        $decisionSnapshot = $this->decisionSnapshotService->createPendingSnapshot(
            array_merge($payload, [
                'career_role_id' => (int) $careerRole->id,
                'career_role_version' => (int) $careerRole->version,
                'student_profile_id' => (int) $studentProfile->id,
                'user_id' => (int) $studentProfile->user_id,
                'target_role' => (string) $careerRole->title,
                'flow' => 'readiness_legacy',
                // Placeholder only — the persisted value always comes from
                // the validated response below. Deliberately NOT the shared
                // `services.data_science.algorithm_version` hint, which
                // defaults to `skill-gap-v1`: that is a different flow's
                // algorithm, which this composite never calls.
                'algorithm_version' => (string) config(
                    'readiness.skill_match.algorithm_version',
                    'skill-match-v1'
                ),
                'composite_algorithm_version' => $compositeVersion,
                'configuration_version' => $configurationVersion,
            ]),
            (string) Str::uuid(),
            $requestId,
        );

        $result = $this->dataScienceClient->analyze(
            $payload,
            $requestId,
        );

        $this->validateDataScienceResult(
            $result,
            $payload,
            $careerRole,
            $roleSkills,
        );

        /*
         * Readiness components supplied by the application layer.
         */
        $practicalExperience = $this->practicalExperienceService->calculate(
            $studentProfile
        );

        $assessmentReliability = $this->assessmentReliabilityService->calculate(
            $studentProfile
        );

        $profileCompleteness = $this->profileCompletenessService->calculate(
            $studentProfile
        );

        /*
         * Missing-component policy (agreed with Data Science): an
         * unavailable component (score === null) is excluded rather than
         * treated as 0 or blocking the calculation. Remaining component
         * weights are renormalized over only the available components,
         * and the result is flagged `is_provisional`. skill_match is
         * always available at this point (validateDataScienceResult()
         * already succeeded above); profile_completeness never returns
         * null by design (see ProfileCompletenessService).
         */
        $weights = config('readiness.weights', []);

        $skillMatch = (float) $result['skill_match_score'];

        $components = [
            'skill_match' => [
                'score' => $skillMatch,
                'weight' => (float) ($weights['skill_match'] ?? 0.65),
                'available' => true,
            ],
            'practical_experience' => [
                'score' => $practicalExperience['score'],
                'weight' => (float) ($weights['practical_experience'] ?? 0.20),
                'available' => $practicalExperience['score'] !== null,
            ],
            'assessment_reliability' => [
                'score' => $assessmentReliability['score'],
                'weight' => (float) ($weights['assessment_reliability'] ?? 0.10),
                'available' => $assessmentReliability['score'] !== null,
            ],
            'profile_completeness' => [
                'score' => $profileCompleteness['score'],
                'weight' => (float) ($weights['profile_completeness'] ?? 0.05),
                'available' => $profileCompleteness['score'] !== null,
            ],
        ];

        $availableComponents = array_filter($components, fn ($c) => $c['available']);
        $excludedComponents = array_keys(array_diff_key($components, $availableComponents));
        $isProvisional = $excludedComponents !== [];

        $availableWeightSum = array_sum(array_column($availableComponents, 'weight'));

        if ($availableWeightSum <= 0.0) {
            // Defensive only — skill_match is always available here, so
            // this should never actually be reached.
            throw new ReadinessIntegrationException(
                'Readiness cannot be calculated because no components are available.',
                422,
                'READINESS_COMPONENT_UNAVAILABLE',
                ['excluded_components' => $excludedComponents],
            );
        }

        $practicalExperienceScore = $practicalExperience['score'] === null
            ? null
            : (float) $practicalExperience['score'];

        $assessmentReliabilityScore = $assessmentReliability['score'] === null
            ? null
            : (float) $assessmentReliability['score'];

        $profileCompletenessScore = $profileCompleteness['score'] === null
            ? null
            : (float) $profileCompleteness['score'];

        /*
         * Calculate the final readiness score using the configured
         * component weights, renormalized over available components only.
         */
        $finalScore = 0.0;
        foreach ($availableComponents as $component) {
            $finalScore += $component['score'] * $component['weight'];
        }
        $finalScore = $finalScore / $availableWeightSum;

        $finalScore = min(max($finalScore, 0.0), 100.0);

        /*
         * ADR-001 §5.1 — the NOMINAL weights above are not what actually
         * produced the score once a component is excluded: the policy
         * redistributes the excluded component's weight proportionally over
         * the available ones. Record the EFFECTIVE weights so a historical
         * result is reproducible rather than merely labelled.
         */
        $effectiveWeights = [];

        foreach ($availableComponents as $name => $component) {
            $effectiveWeights[$name] = round($component['weight'] / $availableWeightSum, 6);
        }

        /*
         * Critical-skill cap.
         */
        $criticalMinimumMatch = (float) config(
            'readiness.critical_skill.minimum_match',
            0.50
        );

        $criticalCap = (float) config(
            'readiness.critical_skill.cap',
            69.0
        );

        $criticalCapApplied = false;
        $criticalSkillNames = [];

        foreach ($result['skill_results'] as $skillResult) {
            if (! (bool) $skillResult['is_critical']) {
                continue;
            }

            // Skill Match v1 already computes match_ratio per skill
            // (0..1, capped at 1.0) — no need to re-derive it from
            // current_level/required_level here.
            if ((float) $skillResult['match_ratio'] < $criticalMinimumMatch) {
                $criticalCapApplied = true;
                $criticalSkillNames[] = (string) $skillResult['skill_name'];
            }
        }

        if ($criticalCapApplied) {
            $finalScore = min($finalScore, $criticalCap);
        }

        $finalScore = round($finalScore, 2);

        $band = $this->resolveBand($finalScore);

        /*
         * The algorithm_version stored with the decision must represent
         * the actual FastAPI algorithm used for this calculation.
         *
         * configuration_version remains the Laravel-side active
         * AlgorithmConfiguration version.
         */
        $algorithmVersion = (string) $result['algorithm_version'];

        /*
         * ADR-001 §5.1 — each component's own algorithm/config version, so a
         * historical composite can be replayed from its parts. skill_match's
         * algorithm_version is FastAPI's validated response value; its config
         * version is the service's own weight configuration.
         */
        $componentVersions = [
            'skill_match' => [
                'algorithm_version' => $algorithmVersion,
                'config_version' => $result['weight_configuration_version'] ?? null,
            ],
            'practical_experience' => [
                'algorithm_version' => $practicalExperience['algorithm_version'] ?? null,
                'config_version' => $practicalExperience['config_version'] ?? null,
            ],
            'assessment_reliability' => [
                'algorithm_version' => $assessmentReliability['algorithm_version'] ?? null,
                'config_version' => $assessmentReliability['config_version'] ?? null,
            ],
            'profile_completeness' => [
                'algorithm_version' => $profileCompleteness['algorithm_version'] ?? null,
                'config_version' => $profileCompleteness['config_version'] ?? null,
            ],
        ];

        $calculatedAt = now();

        return DB::transaction(function () use (
            $studentProfile,
            $careerRole,
            $result,
            $payload,
            $finalScore,
            $skillMatch,
            $practicalExperienceScore,
            $assessmentReliabilityScore,
            $profileCompletenessScore,
            $criticalCapApplied,
            $criticalSkillNames,
            $isProvisional,
            $excludedComponents,
            $band,
            $algorithmVersion,
            $compositeVersion,
            $componentVersions,
            $effectiveWeights,
            $configurationVersion,
            $calculatedAt,
            $requestId,
            $decisionSnapshot,
            $weights,
            $criticalMinimumMatch,
            $criticalCap,
        ) {
            $snapshot = $decisionSnapshot;

            /*
             * Mark the Decision Snapshot as successfully completed only
             * after the FastAPI response has passed validation.
             */
            $snapshot->update([
                'algorithm_version' => $algorithmVersion,
                'status' => DecisionSnapshot::STATUS_SUCCEEDED,
                'calculated_at' => $calculatedAt,
                'snapshot' => [
                    'flow' => 'readiness_legacy',
                    'career_role_id' => (int) $careerRole->id,
                    'career_role_version' => (int) $careerRole->version,
                    'student_profile_id' => (int) $studentProfile->id,
                    'payload' => $payload,
                    'fastapi_result' => $result,
                    'algorithm_version' => $algorithmVersion,
                    'composite_algorithm_version' => $compositeVersion,
                    'configuration_version' => $configurationVersion,
                    'component_versions' => $componentVersions,
                    'request_id' => $requestId,
                    'calculated_at' => $calculatedAt->toIso8601String(),
                ],
            ]);

            /*
             * Persist the final readiness result.
             *
             * Historical results are append-only: every successful
             * calculation creates a new record.
             */
            $readinessResult = ReadinessResult::create([
                'student_profile_id' => $studentProfile->id,
                'career_role_id' => $careerRole->id,
                'career_role_version' => $careerRole->version,
                'decision_snapshot_id' => $snapshot->id,

                'score' => $finalScore,
                'skill_match_component' => $skillMatch,
                'practical_experience_component' => $practicalExperienceScore,
                'assessment_reliability_component' => $assessmentReliabilityScore,
                'profile_completeness_component' => $profileCompletenessScore,

                'critical_cap_applied' => $criticalCapApplied,
                'is_provisional' => $isProvisional,
                'band' => $band,

                'algorithm_version' => $algorithmVersion,
                'composite_algorithm_version' => $compositeVersion,
                'configuration_version' => $configurationVersion,
                'request_id' => $requestId,
                'calculated_at' => $calculatedAt,

                'snapshot' => [
                    'decision_uuid' => $snapshot->decision_uuid,
                    'payload' => $payload,
                    'fastapi_result' => $result,

                    'components' => [
                        'skill_match' => $skillMatch,
                        'practical_experience' => $practicalExperienceScore,
                        'assessment_reliability' => $assessmentReliabilityScore,
                        'profile_completeness' => $profileCompletenessScore,
                    ],

                    /*
                     * ADR-001 §5.1 — which components were unavailable and
                     * therefore excluded, plus the exact weights applied after
                     * redistribution. Together with the version triple
                     * (composite / configuration / algorithm) these make the
                     * score reproducible rather than merely labelled.
                     */
                    'missing_component_policy' => [
                        'excluded_components' => $excludedComponents,
                        'is_provisional' => $isProvisional,
                    ],

                    'formula' => [
                        'weights' => $weights,
                        'effective_weights' => $effectiveWeights,
                        'final_score' => $finalScore,
                    ],

                    'component_versions' => $componentVersions,

                    'critical_skill_rule' => [
                        'minimum_match' => $criticalMinimumMatch,
                        'cap' => $criticalCap,
                        'applied' => $criticalCapApplied,
                        'critical_skill_names' => $criticalSkillNames,
                    ],

                    'algorithm_version' => $algorithmVersion,
                    'composite_algorithm_version' => $compositeVersion,
                    'configuration_version' => $configurationVersion,
                    'request_id' => $requestId,
                    'calculated_at' => $calculatedAt->toIso8601String(),
                ],
            ]);

            /*
             * US-INT-01 §17:
             * Store every validated Skill Match v1 result as an
             * independent, historical, append-only record tied to this
             * decision. Skill Match v1 does not provide per-skill
             * confidence/explanation (that was a /skill-gap-only concept),
             * so those two columns are stored as null going forward.
             */
            foreach ($result['skill_results'] as $skillResult) {
                $requiredLevel = (float) $skillResult['required_level'];
                $achievedLevel = (float) $skillResult['achieved_level'];

                SkillGapResult::create([
                    'decision_snapshot_id' => $snapshot->id,
                    'skill_id' => (int) $skillResult['skill_id'],
                    'current_level' => (float) $skillResult['current_level'],
                    'required_level' => $requiredLevel,
                    'gap' => max($requiredLevel - $achievedLevel, 0.0),
                    'match_score' => round(((float) $skillResult['match_ratio']) * 100, 2),
                    'importance_weight' => (float) $skillResult['importance_weight'],
                    'is_critical' => (bool) $skillResult['is_critical'],
                    'confidence' => null,
                    'status' => (string) $skillResult['status'],
                    'explanation' => null,
                ]);
            }

            return $readinessResult;
        });
    }

    public function latest(
        StudentProfile $studentProfile,
        ?int $careerRoleId = null
    ): ?ReadinessResult {
        return ReadinessResult::query()
            ->where('student_profile_id', $studentProfile->id)
            ->when(
                $careerRoleId,
                fn ($query) => $query->where(
                    'career_role_id',
                    $careerRoleId
                )
            )
            ->latest('calculated_at')
            ->latest('id')
            ->first();
    }

    private function resolveBand(float $score): string
    {
        foreach (config('readiness.bands', []) as $band => $range) {
            if (
                $score >= (float) $range['min']
                && $score <= (float) $range['max']
            ) {
                return $band;
            }
        }

        return 'highly_ready';
    }

    private function validateDataScienceResult(
        array $result,
        array $payload,
        CareerRole $careerRole,
        $roleSkills,
    ): void {
        // Skill Match v1 contract, confirmed by Data Science.
        $required = [
            'student_profile_id',
            'career_role_id',
            'career_role_version',
            'user_id',
            'target_role',
            'algorithm_version',
            'weight_configuration_version',
            'original_weight_total',
            'normalized_weight_total',
            'weighted_achieved_total',
            'weighted_required_total',
            'skill_match_score',
            'total_skills',
            'met_skills',
            'partial_skills',
            'not_required_skills',
            'skill_results',
        ];

        foreach ($required as $key) {
            if (! array_key_exists($key, $result)) {
                throw new ReadinessIntegrationException(
                    'The Data Science service returned an incomplete response.',
                    502,
                    'DATA_SCIENCE_INVALID_RESPONSE',
                    ['missing_field' => $key],
                );
            }
        }

        if (
            (int) $result['student_profile_id']
            !== (int) $payload['student_profile_id']
        ) {
            throw new ReadinessIntegrationException(
                'The Data Science response does not match the student profile.',
                502,
                'DATA_SCIENCE_RESPONSE_MISMATCH',
            );
        }

        if (
            (int) $result['career_role_id']
            !== (int) $payload['career_role_id']
        ) {
            throw new ReadinessIntegrationException(
                'The Data Science response does not match the career role.',
                502,
                'DATA_SCIENCE_RESPONSE_MISMATCH',
            );
        }

        if (
            (int) $result['career_role_version']
            !== (int) $payload['career_role_version']
        ) {
            throw new ReadinessIntegrationException(
                'The Data Science response does not match the career role version.',
                502,
                'DATA_SCIENCE_RESPONSE_MISMATCH',
            );
        }

        if (
            (int) $result['user_id']
            !== (int) $payload['user_id']
        ) {
            throw new ReadinessIntegrationException(
                'The Data Science response does not match the requesting user.',
                502,
                'DATA_SCIENCE_RESPONSE_MISMATCH',
            );
        }

        if (
            (string) $result['target_role']
            !== (string) $careerRole->title
        ) {
            throw new ReadinessIntegrationException(
                'The Data Science response does not match the requested career role.',
                502,
                'DATA_SCIENCE_RESPONSE_MISMATCH',
            );
        }

        foreach (['algorithm_version', 'weight_configuration_version'] as $field) {
            if (
                ! is_string($result[$field])
                || trim($result[$field]) === ''
            ) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains an invalid version field.',
                    502,
                    'DATA_SCIENCE_INVALID_RESPONSE',
                    ['field' => $field],
                );
            }
        }

        if (
            ! is_numeric($result['skill_match_score'])
            || (float) $result['skill_match_score'] < 0
            || (float) $result['skill_match_score'] > 100
        ) {
            throw new ReadinessIntegrationException(
                'The Data Science response contains an invalid skill match score.',
                502,
                'DATA_SCIENCE_INVALID_RESPONSE',
                ['field' => 'skill_match_score'],
            );
        }

        if (! is_array($result['skill_results'])) {
            throw new ReadinessIntegrationException(
                'The Data Science response contains invalid skill results.',
                502,
                'DATA_SCIENCE_INVALID_RESPONSE',
            );
        }

        if (
            count($result['skill_results'])
            !== $roleSkills->count()
        ) {
            throw new ReadinessIntegrationException(
                'The Data Science response contains an unexpected number of skill results.',
                502,
                'DATA_SCIENCE_INVALID_RESPONSE',
            );
        }

        // The importance weights are normalized internally by Data
        // Science: normalized_weight_i = importance_weight_i /
        // SUM(importance_weight). Recompute the expected sum from our own
        // payload so we can cross-check normalized_importance_weight below.
        $weightTotal = array_sum(array_column($payload['skills'], 'importance_weight'));

        $expectedSkills = [];

        foreach ($payload['skills'] as $skill) {
            $expectedSkills[(int) $skill['skill_id']] = $skill;
        }

        $seenSkillIds = [];

        foreach ($result['skill_results'] as $skillResult) {
            foreach ([
                'skill_id',
                'skill_name',
                'current_level',
                'required_level',
                'importance_weight',
                'normalized_importance_weight',
                'achieved_level',
                'match_ratio',
                'is_critical',
                'status',
            ] as $field) {
                if (! array_key_exists($field, $skillResult)) {
                    throw new ReadinessIntegrationException(
                        'The Data Science response contains an incomplete skill result.',
                        502,
                        'DATA_SCIENCE_INVALID_RESPONSE',
                        ['missing_field' => $field],
                    );
                }
            }

            $skillId = (int) $skillResult['skill_id'];

            if (! isset($expectedSkills[$skillId])) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains an unknown skill.',
                    502,
                    'DATA_SCIENCE_INVALID_RESPONSE',
                    ['skill_id' => $skillId],
                );
            }

            if (in_array($skillId, $seenSkillIds, true)) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains duplicate skill results.',
                    502,
                    'DATA_SCIENCE_INVALID_RESPONSE',
                    ['skill_id' => $skillId],
                );
            }

            $expected = $expectedSkills[$skillId];

            if (
                (string) $skillResult['skill_name']
                !== (string) $expected['skill_name']
            ) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains a mismatched skill name.',
                    502,
                    'DATA_SCIENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }

            if (
                abs(
                    (float) $skillResult['current_level']
                    - (float) $expected['current_level']
                ) > 0.0001
            ) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains a mismatched current level.',
                    502,
                    'DATA_SCIENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }

            if (
                abs(
                    (float) $skillResult['required_level']
                    - (float) $expected['required_level']
                ) > 0.0001
            ) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains a mismatched required level.',
                    502,
                    'DATA_SCIENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }

            if (
                abs(
                    (float) $skillResult['importance_weight']
                    - (float) $expected['importance_weight']
                ) > 0.0001
            ) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains a mismatched importance weight.',
                    502,
                    'DATA_SCIENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }

            if (
                (bool) $skillResult['is_critical']
                !== (bool) $expected['is_critical']
            ) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains a mismatched critical flag.',
                    502,
                    'DATA_SCIENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }

            // normalized_importance_weight = importance_weight / SUM(importance_weight)
            $expectedNormalizedWeight = $weightTotal > 0
                ? (float) $expected['importance_weight'] / $weightTotal
                : 0.0;

            if (
                abs(
                    (float) $skillResult['normalized_importance_weight']
                    - $expectedNormalizedWeight
                ) > 0.001
            ) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains a mismatched normalized importance weight.',
                    502,
                    'DATA_SCIENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }

            $requiredLevel = (float) $expected['required_level'];
            $currentLevel = (float) $expected['current_level'];

            // achieved_level is current_level capped at required_level —
            // over-qualification never counts beyond 100% of what the
            // role requires (contract note: "the result never exceeds
            // 100%").
            $expectedAchievedLevel = $requiredLevel > 0
                ? min($currentLevel, $requiredLevel)
                : $currentLevel;

            if (
                abs(
                    (float) $skillResult['achieved_level']
                    - $expectedAchievedLevel
                ) > 0.0001
            ) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains a mismatched achieved level.',
                    502,
                    'DATA_SCIENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }

            // required_level = 0 => not_required, match_ratio not
            // meaningfully constrained beyond the 0..1 Pydantic schema
            // bound (already enforced by FastAPI).
            if ($requiredLevel > 0) {
                $expectedMatchRatio = min(
                    max($expectedAchievedLevel / $requiredLevel, 0.0),
                    1.0
                );

                if (
                    abs(
                        (float) $skillResult['match_ratio']
                        - $expectedMatchRatio
                    ) > 0.01
                ) {
                    throw new ReadinessIntegrationException(
                        'The Data Science response contains a mismatched match ratio.',
                        502,
                        'DATA_SCIENCE_RESPONSE_MISMATCH',
                        ['skill_id' => $skillId],
                    );
                }

                $expectedStatus = $expectedMatchRatio >= 1.0 ? 'met' : 'partial';
            } else {
                $expectedStatus = 'not_required';
            }

            if (
                (string) $skillResult['status']
                !== $expectedStatus
            ) {
                throw new ReadinessIntegrationException(
                    'The Data Science response contains a mismatched skill status.',
                    502,
                    'DATA_SCIENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }

            $seenSkillIds[] = $skillId;
        }

        Log::debug(
            'Validated Data Science skill-match response.',
            [
                'career_role_id' => $careerRole->id,
                'career_role_version' => $careerRole->version,
                'result_skill_count' => count($result['skill_results']),
                'algorithm_version' => $result['algorithm_version'],
                'weight_configuration_version' => $result['weight_configuration_version'],
            ],
        );
    }
}
