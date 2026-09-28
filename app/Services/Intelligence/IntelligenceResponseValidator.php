<?php

namespace App\Services\Intelligence;

use App\Exceptions\IntelligenceException;

/**
 * US-INT-01 §16 — never trust FastAPI JSON. Every response is checked
 * against the payload that produced it (identity echo), scale/range
 * rules, enum membership, and cross-field invariants. Any violation
 * throws before a single row is persisted — no fabricated results.
 */
class IntelligenceResponseValidator
{
    public function validateCommonIdentity(array $result, array $payload): void
    {
        foreach (['student_profile_id', 'career_role_id', 'career_role_version'] as $key) {
            if (! array_key_exists($key, $result)) {
                throw new IntelligenceException(
                    'The intelligence service response is missing mandatory identity metadata.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['missing_field' => $key],
                );
            }
        }

        if ((int) $result['student_profile_id'] !== (int) $payload['learner']['student_profile_id']) {
            throw new IntelligenceException(
                'The intelligence service response does not match the learner.',
                502,
                'INTELLIGENCE_RESPONSE_MISMATCH',
            );
        }

        if ((int) $result['career_role_id'] !== (int) $payload['role']['id']) {
            throw new IntelligenceException(
                'The intelligence service response does not match the career role.',
                502,
                'INTELLIGENCE_RESPONSE_MISMATCH',
            );
        }

        if ((int) $result['career_role_version'] !== (int) $payload['role']['version']) {
            throw new IntelligenceException(
                'The intelligence service response does not match the career role version.',
                502,
                'INTELLIGENCE_RESPONSE_MISMATCH',
            );
        }
    }

    /**
     * Skill-gap contract: one complete result per payload skill, no
     * unknown/duplicate skills, gap = max(required-current, 0) within
     * tolerance, status derived from the gap.
     */
    public function validateSkillGap(array $result, array $payload): void
    {
        $this->validateCommonIdentity($result, $payload);
        $this->validateAlgorithmVersion($result);

        if (! array_key_exists('skill_results', $result) || ! is_array($result['skill_results'])) {
            throw new IntelligenceException(
                'The intelligence service response contains invalid skill results.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        $expected = [];

        foreach ($payload['skills'] as $skill) {
            $expected[(int) $skill['skill_id']] = $skill;
        }

        if (count($result['skill_results']) !== count($expected)) {
            throw new IntelligenceException(
                'The intelligence service returned an unexpected number of skill results.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        $seen = [];

        foreach ($result['skill_results'] as $skillResult) {
            foreach ([
                'skill_id',
                'current_level',
                'required_level',
                'importance_weight',
                'is_critical',
                'gap',
                'status',
            ] as $field) {
                if (! array_key_exists($field, $skillResult)) {
                    throw new IntelligenceException(
                        'The intelligence service response contains an incomplete skill result.',
                        502,
                        'INTELLIGENCE_INVALID_RESPONSE',
                        ['missing_field' => $field],
                    );
                }
            }

            $skillId = (int) $skillResult['skill_id'];

            if (! isset($expected[$skillId])) {
                throw new IntelligenceException(
                    'The intelligence service response contains an unknown skill.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['skill_id' => $skillId],
                );
            }

            if (in_array($skillId, $seen, true)) {
                throw new IntelligenceException(
                    'The intelligence service response contains duplicate skill results.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['skill_id' => $skillId],
                );
            }

            $seen[] = $skillId;
            $input = $expected[$skillId];

            $currentLevel = $this->assertNumberInRange($skillResult, 'current_level', 0, 5);
            $requiredLevel = $this->assertNumberInRange($skillResult, 'required_level', 0, 5);

            if (abs($currentLevel - (float) $input['current_level']) > 0.0001) {
                throw new IntelligenceException(
                    'The intelligence service response contains a mismatched current level.',
                    502,
                    'INTELLIGENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }

            if (abs($requiredLevel - (float) $input['required_level']) > 0.0001) {
                throw new IntelligenceException(
                    'The intelligence service response contains a mismatched required level.',
                    502,
                    'INTELLIGENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }

            if (abs((float) $skillResult['importance_weight'] - (float) $input['importance_weight']) > 0.0001) {
                throw new IntelligenceException(
                    'The intelligence service response contains a mismatched importance weight.',
                    502,
                    'INTELLIGENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }

            if ((bool) $skillResult['is_critical'] !== (bool) $input['is_critical']) {
                throw new IntelligenceException(
                    'The intelligence service response contains a mismatched critical flag.',
                    502,
                    'INTELLIGENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }

            $gap = (float) $skillResult['gap'];

            if ($gap < 0 || $gap > 5) {
                throw new IntelligenceException(
                    'The intelligence service response contains an invalid skill gap.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['skill_id' => $skillId],
                );
            }

            $expectedGap = max((float) $input['required_level'] - (float) $input['current_level'], 0.0);

            if (abs($gap - $expectedGap) > 0.01) {
                throw new IntelligenceException(
                    'The intelligence service response contains a mismatched skill gap.',
                    502,
                    'INTELLIGENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }

            if (array_key_exists('match_score', $skillResult) && $skillResult['match_score'] !== null) {
                $this->assertNumberInRange($skillResult, 'match_score', 0, 100);
            }

            $expectedStatus = $expectedGap == 0.0 ? 'met' : 'gap';

            if ((string) $skillResult['status'] !== $expectedStatus) {
                throw new IntelligenceException(
                    'The intelligence service response contains a mismatched skill status.',
                    502,
                    'INTELLIGENCE_RESPONSE_MISMATCH',
                    ['skill_id' => $skillId],
                );
            }
        }
    }

    /**
     * Readiness contract: 0..100 scores, skill counts, and — when the service
     * supplies them — the critical-cap metadata and its internal consistency.
     *
     * The cap metadata is validated but NOT required. Laravel owns the
     * Composite and the Critical Cap, so the service must never be obliged to
     * report them; see the note in the body.
     */
    public function validateReadiness(array $result, array $payload): void
    {
        $this->validateCommonIdentity($result, $payload);
        $this->validateAlgorithmVersion($result);

        $required = [
            'readiness_score',
            'base_readiness_score',
            'total_skills',
            'met_skills',
        ];

        foreach ($required as $key) {
            if (! array_key_exists($key, $result)) {
                throw new IntelligenceException(
                    'The intelligence service response is missing mandatory readiness metadata.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['missing_field' => $key],
                );
            }
        }

        foreach (['readiness_score', 'base_readiness_score'] as $field) {
            if (
                ! is_numeric($result[$field])
                || (float) $result[$field] < 0
                || (float) $result[$field] > 100
            ) {
                throw new IntelligenceException(
                    'The intelligence service response contains an invalid readiness score.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['field' => $field],
                );
            }
        }

        if (! is_int($result['total_skills']) || $result['total_skills'] !== count($payload['skills'])) {
            throw new IntelligenceException(
                'The intelligence service response contains an invalid total skill count.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        if (! is_int($result['met_skills']) || $result['met_skills'] < 0) {
            throw new IntelligenceException(
                'The intelligence service response contains an invalid met skill count.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        /*
         * Critical-cap metadata is OPTIONAL, and that is deliberate.
         *
         * Skill Match v1 returns per-skill `is_critical` and `match_ratio` and
         * no cap metadata at all. Laravel is the owner of the Composite and of
         * the Critical Cap, so the service must never be *required* to report
         * the outcome of a decision Laravel makes. Requiring these fields
         * pinned us to a contract the service does not implement.
         *
         * Each field is still validated whenever the service does send it, so
         * tolerating absence costs no coverage.
         */
        if (array_key_exists('critical_skill_cap_applied', $result)
            && ! is_bool($result['critical_skill_cap_applied'])
        ) {
            throw new IntelligenceException(
                'The intelligence service response contains an invalid critical cap flag.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        if (array_key_exists('skills_with_gap', $result)) {
            $skillsWithGap = $result['skills_with_gap'];

            if (
                ! is_int($skillsWithGap) || $skillsWithGap < 0
                || ($result['met_skills'] + $skillsWithGap) !== $result['total_skills']
            ) {
                throw new IntelligenceException(
                    'The intelligence service response contains inconsistent skill counts.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                );
            }
        }

        $hasCriticalNames = array_key_exists('critical_skill_names', $result);
        $hasCriticalCount = array_key_exists('critical_skill_gap_count', $result);

        if ($hasCriticalNames && ! is_array($result['critical_skill_names'])) {
            throw new IntelligenceException(
                'The intelligence service response contains invalid critical skill metadata.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        if ($hasCriticalCount && ! is_int($result['critical_skill_gap_count'])) {
            throw new IntelligenceException(
                'The intelligence service response contains invalid critical skill metadata.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        // Only meaningful when both are present; either may be omitted.
        if (
            $hasCriticalNames && $hasCriticalCount
            && $result['critical_skill_gap_count'] !== count($result['critical_skill_names'])
        ) {
            throw new IntelligenceException(
                'The intelligence service response contains inconsistent critical skill counts.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }
    }

    /**
     * Roadmap contract: version/status echo, phases with actions, known
     * target skills, priority in 0..1, positive effort/duration, and a
     * valid next best action pointing at a returned action.
     */
    public function validateRoadmap(array $result, array $payload): void
    {
        $this->validateCommonIdentity($result, $payload);
        $this->validateAlgorithmVersion($result);

        foreach (['roadmap_version', 'status', 'phases'] as $key) {
            if (! array_key_exists($key, $result)) {
                throw new IntelligenceException(
                    'The intelligence service response is missing mandatory roadmap metadata.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['missing_field' => $key],
                );
            }
        }

        if (! is_int($result['roadmap_version']) || $result['roadmap_version'] < 1) {
            throw new IntelligenceException(
                'The intelligence service response contains an invalid roadmap version.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        if (! is_string($result['status']) || $result['status'] === '') {
            throw new IntelligenceException(
                'The intelligence service response contains an invalid roadmap status.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        if (! is_array($result['phases'])) {
            throw new IntelligenceException(
                'The intelligence service response contains invalid roadmap phases.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        $knownSkillIds = array_map(
            static fn (array $skill): int => (int) $skill['skill_id'],
            $payload['skills'],
        );

        $actionIds = [];

        foreach ($result['phases'] as $phase) {
            $this->validatePhase($phase, $knownSkillIds, $actionIds);
        }

        if (array_key_exists('next_best_action_id', $result)
            && $result['next_best_action_id'] !== null
            && ! in_array((string) $result['next_best_action_id'], $actionIds, true)) {
            throw new IntelligenceException(
                'The intelligence service response contains an invalid next best action.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }
    }

    /**
     * @param  list<string>  $knownSkillIds
     * @param  list<string>  $actionIds
     */
    private function validatePhase(array $phase, array $knownSkillIds, array &$actionIds): void
    {
        foreach (['phase', 'actions'] as $key) {
            if (! array_key_exists($key, $phase)) {
                throw new IntelligenceException(
                    'The intelligence service response contains an incomplete roadmap phase.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['missing_field' => $key],
                );
            }
        }

        if (! is_string($phase['phase']) || $phase['phase'] === '') {
            throw new IntelligenceException(
                'The intelligence service response contains an invalid roadmap phase name.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        if (! is_array($phase['actions'])) {
            throw new IntelligenceException(
                'The intelligence service response contains invalid phase actions.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        foreach ($phase['actions'] as $action) {
            $this->validateAction($action, $phase['phase'], $knownSkillIds, $actionIds);
        }
    }

    /**
     * @param  list<string>  $knownSkillIds
     * @param  list<string>  $actionIds
     */
    private function validateAction(array $action, string $phaseName, array $knownSkillIds, array &$actionIds): void
    {
        foreach (['action_id', 'action_type', 'title'] as $key) {
            if (! array_key_exists($key, $action)) {
                throw new IntelligenceException(
                    'The intelligence service response contains an incomplete roadmap action.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['missing_field' => $key, 'phase' => $phaseName],
                );
            }
        }

        $actionId = (string) $action['action_id'];

        if ($actionId === '' || in_array($actionId, $actionIds, true)) {
            throw new IntelligenceException(
                'The intelligence service response contains duplicate or empty action ids.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['phase' => $phaseName],
            );
        }

        $actionIds[] = $actionId;

        if (array_key_exists('target_skill_id', $action) && $action['target_skill_id'] !== null) {
            $targetSkillId = (int) $action['target_skill_id'];

            // $knownSkillIds is a list of ints — compare int to int.
            if (! in_array($targetSkillId, $knownSkillIds, true)) {
                throw new IntelligenceException(
                    'The intelligence service response contains an unknown target skill.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['skill_id' => $targetSkillId, 'phase' => $phaseName],
                );
            }
        }

        if (array_key_exists('prerequisite_skill_ids', $action) && is_array($action['prerequisite_skill_ids'])) {
            foreach ($action['prerequisite_skill_ids'] as $prerequisiteSkillId) {
                if (! in_array((int) $prerequisiteSkillId, $knownSkillIds, true)) {
                    throw new IntelligenceException(
                        'The intelligence service response contains an unknown prerequisite skill.',
                        502,
                        'INTELLIGENCE_INVALID_RESPONSE',
                        ['skill_id' => (int) $prerequisiteSkillId, 'phase' => $phaseName],
                    );
                }
            }
        }

        if (array_key_exists('priority_score', $action) && $action['priority_score'] !== null) {
            $this->assertNumberInRange($action, 'priority_score', 0, 1);
        }

        foreach (['estimated_hours', 'estimated_duration_hours'] as $field) {
            if (array_key_exists($field, $action) && $action[$field] !== null) {
                $value = $action[$field];

                if (! is_numeric($value) || (float) $value <= 0) {
                    throw new IntelligenceException(
                        'The intelligence service response contains an invalid action effort estimate.',
                        502,
                        'INTELLIGENCE_INVALID_RESPONSE',
                        ['field' => $field, 'phase' => $phaseName],
                    );
                }
            }
        }
    }

    private function validateAlgorithmVersion(array $result): void
    {
        if (
            ! array_key_exists('algorithm_version', $result)
            || ! is_string($result['algorithm_version'])
            || trim($result['algorithm_version']) === ''
        ) {
            throw new IntelligenceException(
                'The intelligence service response is missing valid algorithm version metadata.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['missing_field' => 'algorithm_version'],
            );
        }
    }

    /**
     * Project Matching contract: the response must carry the request_id
     * echo and a recommendation block whose fields are validated against
     * the verified FastAPI ProjectMatchingResponse schema.
     *
     * @param  string  $requestId  the request_id that was sent in the payload
     * @param  array<string, mixed>|null  $expectedVersions  ['algorithm_version' => ..., 'configuration_version' => ..., 'project_id' => ..., 'project_version' => ...]
     */
    public function validateProjectMatching(array $result, string $requestId, ?array $expectedVersions = null): void
    {
        if (! array_key_exists('request_id', $result) || ! is_string($result['request_id']) || trim($result['request_id']) === '') {
            throw new IntelligenceException(
                'The intelligence service response is missing a valid request_id echo.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['missing_field' => 'request_id'],
            );
        }

        if ($requestId !== '' && (string) $result['request_id'] !== $requestId) {
            throw new IntelligenceException(
                'The intelligence service response does not match the request_id.',
                502,
                'INTELLIGENCE_RESPONSE_MISMATCH',
            );
        }

        /*
         * algorithm_version and configuration_version are REQUIRED by the
         * agreed Project Matching contract. The service must echo the exact
         * versions Laravel sent — a missing, null or empty value is a
         * contract violation, never something to paper over with a default.
         *
         * The expected values are supplied by the caller, taken from the
         * payload the integration architecture already built
         * (ProjectMatchingPayloadBuilder <- ProjectMatchingSnapshotService),
         * so no version literal is hardcoded here.
         */
        $this->validateRequiredVersion($result, 'algorithm_version', $expectedVersions);
        $this->validateRequiredVersion($result, 'configuration_version', $expectedVersions);

        if (! array_key_exists('recommendation', $result) || ! is_array($result['recommendation'])) {
            throw new IntelligenceException(
                'The intelligence service response is missing the recommendation block.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['missing_field' => 'recommendation'],
            );
        }

        $this->validateRecommendation($result['recommendation']);

        if ($expectedVersions !== null) {
            $this->validateProjectCorrelation($result['recommendation'], $expectedVersions);
        }
    }

    /**
     * Validate one REQUIRED version field on the Project Matching response.
     *
     * The field must be present, a string, and non-empty — a missing, null
     * or blank value is rejected outright. When the caller supplied the
     * value it sent (the normal case: the expected versions travel with the
     * payload), the response must match it exactly; the service may never
     * substitute its own default.
     *
     * The expected value comes from the integration architecture, so this
     * method contains no version literal of its own.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $expectedVersions
     */
    private function validateRequiredVersion(array $result, string $field, ?array $expectedVersions): void
    {
        if (
            ! array_key_exists($field, $result)
            || ! is_string($result[$field])
            || trim($result[$field]) === ''
        ) {
            throw new IntelligenceException(
                'The intelligence service response is missing a valid '.$field.'.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['missing_field' => $field],
            );
        }

        $expected = $expectedVersions[$field] ?? null;

        // No expectation supplied: presence is still enforced above, but
        // there is nothing to compare the echoed value against.
        if (! is_string($expected) || trim($expected) === '') {
            return;
        }

        if ($result[$field] !== $expected) {
            throw new IntelligenceException(
                'The intelligence service returned an inconsistent '.$field.'.',
                502,
                'INTELLIGENCE_RESPONSE_MISMATCH',
                [
                    'expected' => $expected,
                    'actual' => $result[$field],
                ],
            );
        }
    }

    /**
     * Verify that the recommendation's project_id and project_version match
     * the project and version that were sent in the request payload.
     */
    private function validateProjectCorrelation(array $recommendation, array $expectedVersions): void
    {
        if (array_key_exists('project_id', $expectedVersions)) {
            $expectedProjectId = (int) $expectedVersions['project_id'];
            $actualProjectId = (int) $recommendation['project_id'];

            if ($expectedProjectId > 0 && $actualProjectId !== $expectedProjectId) {
                throw new IntelligenceException(
                    'The intelligence service response does not match the project_id.',
                    502,
                    'INTELLIGENCE_RESPONSE_MISMATCH',
                    [
                        'expected' => $expectedProjectId,
                        'actual' => $actualProjectId,
                    ],
                );
            }
        }

        if (array_key_exists('project_version', $expectedVersions)) {
            $expectedProjectVersion = (int) $expectedVersions['project_version'];
            $actualProjectVersion = (int) $recommendation['project_version'];

            if ($expectedProjectVersion > 0 && $actualProjectVersion !== $expectedProjectVersion) {
                throw new IntelligenceException(
                    'The intelligence service response does not match the project_version.',
                    502,
                    'INTELLIGENCE_RESPONSE_MISMATCH',
                    [
                        'expected' => $expectedProjectVersion,
                        'actual' => $actualProjectVersion,
                    ],
                );
            }
        }
    }

    /**
     * Validate the recommendation block (ProjectRecommendationResult).
     */
    private function validateRecommendation(array $recommendation): void
    {
        foreach (['project_id', 'project_version', 'eligibility_state', 'matching_state', 'score', 'factor_scores', 'weighted_contributions'] as $key) {
            if (! array_key_exists($key, $recommendation)) {
                throw new IntelligenceException(
                    'The intelligence service response contains an incomplete recommendation.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['missing_field' => $key],
                );
            }
        }

        if (! is_int($recommendation['project_id']) || $recommendation['project_id'] <= 0) {
            throw new IntelligenceException(
                'The recommendation contains an invalid project_id.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['field' => 'project_id'],
            );
        }

        if (! is_int($recommendation['project_version']) || $recommendation['project_version'] <= 0) {
            throw new IntelligenceException(
                'The recommendation contains an invalid project_version.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['field' => 'project_version'],
            );
        }

        if (! is_string($recommendation['eligibility_state'])
            || ! in_array($recommendation['eligibility_state'], ['eligible', 'ineligible'], true)
        ) {
            throw new IntelligenceException(
                'The recommendation contains an invalid eligibility_state.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['field' => 'eligibility_state'],
            );
        }

        if (! is_string($recommendation['matching_state'])
            || ! in_array($recommendation['matching_state'], ['scored', 'blocked'], true)
        ) {
            throw new IntelligenceException(
                'The recommendation contains an invalid matching_state.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['field' => 'matching_state'],
            );
        }

        $this->assertNumberInRange($recommendation, 'score', 0, 100);

        if (! is_array($recommendation['factor_scores']) || ! is_array($recommendation['weighted_contributions'])) {
            throw new IntelligenceException(
                'The recommendation contains invalid factor_scores or weighted_contributions.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        foreach (['skill_compatibility', 'learning_value', 'career_relevance', 'interest_match', 'availability_fit'] as $field) {
            $this->assertNumberInRange($recommendation['factor_scores'], $field, 0, 100);
            $this->assertNumberInRange($recommendation['weighted_contributions'], $field, 0, 100);
        }

        if (array_key_exists('explanation', $recommendation)) {
            if (! is_array($recommendation['explanation'])) {
                throw new IntelligenceException(
                    'The recommendation contains an invalid explanation array.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['field' => 'explanation'],
                );
            }

            foreach ($recommendation['explanation'] as $item) {
                if (! is_string($item)) {
                    throw new IntelligenceException(
                        'The recommendation explanation must contain only strings.',
                        502,
                        'INTELLIGENCE_INVALID_RESPONSE',
                        ['field' => 'explanation'],
                    );
                }
            }
        }

        if (array_key_exists('limiting_factors', $recommendation)) {
            if (! is_array($recommendation['limiting_factors'])) {
                throw new IntelligenceException(
                    'The recommendation contains an invalid limiting_factors array.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['field' => 'limiting_factors'],
                );
            }

            foreach ($recommendation['limiting_factors'] as $item) {
                if (! is_string($item)) {
                    throw new IntelligenceException(
                        'The recommendation limiting_factors must contain only strings.',
                        502,
                        'INTELLIGENCE_INVALID_RESPONSE',
                        ['field' => 'limiting_factors'],
                    );
                }
            }
        }

        // skill_results is OPTIONAL in ProjectRecommendationResult, but when
        // it IS present it must be an array of objects. A null, string or
        // object value is a contract violation — not a reason to skip the
        // per-entry validation silently.
        if (array_key_exists('skill_results', $recommendation)) {
            if (! is_array($recommendation['skill_results'])) {
                throw new IntelligenceException(
                    'The recommendation contains an invalid skill_results array.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['field' => 'skill_results'],
                );
            }

            foreach ($recommendation['skill_results'] as $skillResult) {
                if (! is_array($skillResult)) {
                    throw new IntelligenceException(
                        'The recommendation skill_results must contain only objects.',
                        502,
                        'INTELLIGENCE_INVALID_RESPONSE',
                        ['field' => 'skill_results'],
                    );
                }

                $this->validateProjectMatchingSkillResult($skillResult);
            }
        }
    }

    /**
     * Validate one ProjectMatchingSkillResult entry.
     */
    private function validateProjectMatchingSkillResult(array $skillResult): void
    {
        foreach (['skill_id', 'current_level', 'minimum_level', 'gap', 'match_ratio', 'is_critical_entry', 'status'] as $key) {
            if (! array_key_exists($key, $skillResult)) {
                throw new IntelligenceException(
                    'The project matching skill result is incomplete.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['missing_field' => $key],
                );
            }
        }

        if (! is_int($skillResult['skill_id']) || $skillResult['skill_id'] <= 0) {
            throw new IntelligenceException(
                'The project matching skill result contains an invalid skill_id.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['field' => 'skill_id'],
            );
        }

        if (array_key_exists('skill_name', $skillResult)
            && $skillResult['skill_name'] !== null
            && ! is_string($skillResult['skill_name'])
        ) {
            throw new IntelligenceException(
                'The project matching skill result contains an invalid skill_name.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['field' => 'skill_name'],
            );
        }

        foreach (['current_level', 'minimum_level', 'gap'] as $field) {
            $this->assertNumberInRange($skillResult, $field, 0, 5);
        }

        $this->assertNumberInRange($skillResult, 'match_ratio', 0, 1);

        if (! is_bool($skillResult['is_critical_entry'])) {
            throw new IntelligenceException(
                'The project matching skill result contains an invalid is_critical_entry.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['field' => 'is_critical_entry'],
            );
        }

        $allowedStatuses = [
            'not_required', 'met', 'manageable_gap', 'large_gap',
            'missing_non_critical', 'missing_critical', 'critical_below_minimum',
        ];

        if (! in_array((string) $skillResult['status'], $allowedStatuses, true)) {
            throw new IntelligenceException(
                'The project matching skill result contains an invalid status.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['field' => 'status'],
            );
        }
    }

    /**
     * @return float the validated numeric value
     */
    private function assertNumberInRange(array $data, string $field, float $min, float $max): float
    {
        if (
            ! array_key_exists($field, $data)
            || ! is_numeric($data[$field])
            || (float) $data[$field] < $min
            || (float) $data[$field] > $max
        ) {
            throw new IntelligenceException(
                "The intelligence service response contains an invalid {$field} value.",
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['field' => $field],
            );
        }

        return (float) $data[$field];
    }
}
