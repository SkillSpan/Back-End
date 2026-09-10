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
     * Readiness contract: 0..100 scores, component invariants, critical
     * cap metadata, and skill-level counts consistent with the gap data.
     */
    public function validateReadiness(array $result, array $payload): void
    {
        $this->validateCommonIdentity($result, $payload);
        $this->validateAlgorithmVersion($result);

        $required = [
            'readiness_score',
            'base_readiness_score',
            'critical_skill_cap_applied',
            'critical_skill_gap_count',
            'critical_skill_names',
            'total_skills',
            'met_skills',
            'skills_with_gap',
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

        if (! is_bool($result['critical_skill_cap_applied'])) {
            throw new IntelligenceException(
                'The intelligence service response contains an invalid critical cap flag.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        if (! is_int($result['total_skills']) || $result['total_skills'] !== count($payload['skills'])) {
            throw new IntelligenceException(
                'The intelligence service response contains an invalid total skill count.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        $metSkills = $result['met_skills'];
        $skillsWithGap = $result['skills_with_gap'];

        if (
            ! is_int($metSkills) || ! is_int($skillsWithGap)
            || $metSkills < 0 || $skillsWithGap < 0
            || ($metSkills + $skillsWithGap) !== $result['total_skills']
        ) {
            throw new IntelligenceException(
                'The intelligence service response contains inconsistent skill counts.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        if (! is_array($result['critical_skill_names']) || ! is_int($result['critical_skill_gap_count'])) {
            throw new IntelligenceException(
                'The intelligence service response contains invalid critical skill metadata.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
            );
        }

        if ($result['critical_skill_gap_count'] !== count($result['critical_skill_names'])) {
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

            if (! in_array((string) $targetSkillId, $knownSkillIds, true)) {
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
                if (! in_array((string) ((int) $prerequisiteSkillId), $knownSkillIds, true)) {
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
