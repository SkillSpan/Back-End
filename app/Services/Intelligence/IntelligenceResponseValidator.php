<?php

namespace App\Services\Intelligence;

use App\Exceptions\IntelligenceException;
use App\Models\RoadmapAction;

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

    public function validateRoadmap(array $result, array $payload): void
    {
        $this->validateCommonIdentity($result, $payload);
        $this->validateAlgorithmVersion($result);
        $this->validateConfigurationVersion($result);

        if (array_key_exists('roadmap_version', $result)) {
            if (! is_int($result['roadmap_version']) || $result['roadmap_version'] < 1) {
                throw new IntelligenceException(
                    'The intelligence service response contains an invalid roadmap version.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                );
            }
        }

        if (array_key_exists('status', $result)) {
            if (! is_string($result['status']) || $result['status'] === '') {
                throw new IntelligenceException(
                    'The intelligence service response contains an invalid roadmap status.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                );
            }
        }

        if (! array_key_exists('phases', $result)) {
            throw new IntelligenceException(
                'The intelligence service response is missing mandatory roadmap metadata.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['missing_field' => 'phases'],
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

        $skillNameById = [];

        foreach ($payload['skills'] as $skill) {
            $skillNameById[(int) $skill['skill_id']] = (string) $skill['skill_name'];
        }

        $weeklyAvailabilityHours = $payload['learner']['weekly_availability_hours'] ?? null;

        $this->validateRoadmapTotals($result, $weeklyAvailabilityHours);
        $this->validateLimitations($result);

        $actionIds = [];

        foreach ($result['phases'] as $phase) {
            $this->validatePhase($phase, $knownSkillIds, $actionIds, $weeklyAvailabilityHours, $skillNameById);
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

    private function validatePhase(array $phase, array $knownSkillIds, array &$actionIds, mixed $weeklyAvailabilityHours, array $skillNameById): void
    {
        foreach (['phase', 'order', 'actions'] as $key) {
            if (! array_key_exists($key, $phase)) {
                throw new IntelligenceException(
                    'The intelligence service response contains an incomplete roadmap phase.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['missing_field' => $key],
                );
            }
        }

        if (! is_string($phase['phase']) || ! array_key_exists($phase['phase'], RoadmapAction::PHASE_ORDER)) {
            throw new IntelligenceException(
                'The intelligence service response contains an unknown roadmap phase name.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['phase' => $phase['phase']],
            );
        }

        if (! is_int($phase['order']) || $phase['order'] !== RoadmapAction::PHASE_ORDER[$phase['phase']]) {
            throw new IntelligenceException(
                'The intelligence service response contains a roadmap phase order that does not match its phase.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['phase' => $phase['phase'], 'order' => $phase['order']],
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
            $this->validateAction($action, $phase['phase'], $knownSkillIds, $actionIds, $weeklyAvailabilityHours, $skillNameById);
        }
    }

    private function validateAction(
        array $action,
        string $phaseName,
        array $knownSkillIds,
        array &$actionIds,
        mixed $weeklyAvailabilityHours,
        array $skillNameById,
    ): void {
        foreach ([
            'action_id',
            'action_type',
            'title',
            'objective',
            'target_skill_id',
            'target_skill_name',
            'priority_score',
            'estimated_hours',
            'estimated_duration_weeks',
            'completion_criteria',
            'explanation',
        ] as $key) {
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

        if (! is_string($action['action_type'])
            || ! in_array($action['action_type'], RoadmapAction::TYPES, true)
        ) {
            throw new IntelligenceException(
                'The intelligence service response contains an unknown roadmap action type.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['action_type' => $action['action_type'], 'phase' => $phaseName],
            );
        }

        foreach (['title', 'objective', 'target_skill_name'] as $field) {
            if (! is_string($action[$field]) || trim($action[$field]) === '') {
                throw new IntelligenceException(
                    'The intelligence service response contains an invalid roadmap action text field.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['field' => $field, 'phase' => $phaseName],
                );
            }
        }

        $this->assertNonEmptyStringList($action, 'completion_criteria', $phaseName);
        $this->assertNonEmptyStringList($action, 'explanation', $phaseName);

        if (! is_int($action['target_skill_id']) || $action['target_skill_id'] <= 0) {
            throw new IntelligenceException(
                'The intelligence service response contains an invalid target skill id.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['skill_id' => $action['target_skill_id'], 'phase' => $phaseName],
            );
        }

        if (! in_array($action['target_skill_id'], $knownSkillIds, true)) {
            throw new IntelligenceException(
                'The intelligence service response contains an unknown target skill.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['skill_id' => $action['target_skill_id'], 'phase' => $phaseName],
            );
        }

        $canonicalSkillName = $skillNameById[$action['target_skill_id']] ?? null;

        if ($canonicalSkillName === null || $action['target_skill_name'] !== $canonicalSkillName) {
            throw new IntelligenceException(
                'The intelligence service response contains a target skill name that does not match the target skill id.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['skill_id' => $action['target_skill_id'], 'phase' => $phaseName],
            );
        }

        if (array_key_exists('prerequisite_skill_ids', $action) && $action['prerequisite_skill_ids'] !== null) {
            if (! is_array($action['prerequisite_skill_ids'])) {
                throw new IntelligenceException(
                    'The intelligence service response contains prerequisite_skill_ids in an invalid format.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['field' => 'prerequisite_skill_ids', 'phase' => $phaseName],
                );
            }

            $seenPrerequisiteSkillIds = [];

            foreach ($action['prerequisite_skill_ids'] as $prerequisiteSkillId) {
                if (! is_int($prerequisiteSkillId)) {
                    throw new IntelligenceException(
                        'The intelligence service response contains a non-integer prerequisite skill id.',
                        502,
                        'INTELLIGENCE_INVALID_RESPONSE',
                        ['skill_id' => $prerequisiteSkillId, 'phase' => $phaseName],
                    );
                }

                if (in_array($prerequisiteSkillId, $seenPrerequisiteSkillIds, true)) {
                    throw new IntelligenceException(
                        'The intelligence service response contains duplicate prerequisite skill ids.',
                        502,
                        'INTELLIGENCE_INVALID_RESPONSE',
                        ['skill_id' => $prerequisiteSkillId, 'phase' => $phaseName],
                    );
                }

                $seenPrerequisiteSkillIds[] = $prerequisiteSkillId;

                if (! in_array($prerequisiteSkillId, $knownSkillIds, true)) {
                    throw new IntelligenceException(
                        'The intelligence service response contains an unknown prerequisite skill.',
                        502,
                        'INTELLIGENCE_INVALID_RESPONSE',
                        ['skill_id' => $prerequisiteSkillId, 'phase' => $phaseName],
                    );
                }
            }
        }

        $this->validateBlockingPrerequisiteSkillIds($action, $knownSkillIds, $phaseName);

        $this->assertNumberInRange($action, 'priority_score', 0, 1);

        if (! is_numeric($action['estimated_hours']) || (float) $action['estimated_hours'] <= 0) {
            throw new IntelligenceException(
                'The intelligence service response contains an invalid action effort estimate.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['field' => 'estimated_hours', 'phase' => $phaseName],
            );
        }

        $this->assertActionDurationWeeks(
            $action['estimated_duration_weeks'],
            $weeklyAvailabilityHours,
            $phaseName,
        );
    }

    private function assertNonEmptyStringList(array $data, string $field, string $phaseName): void
    {
        $value = $data[$field];

        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw new IntelligenceException(
                'The intelligence service response contains an invalid roadmap action list field.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['field' => $field, 'phase' => $phaseName],
            );
        }

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new IntelligenceException(
                    'The intelligence service response contains an invalid roadmap action list field.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['field' => $field, 'phase' => $phaseName],
                );
            }
        }
    }

    private function validateBlockingPrerequisiteSkillIds(array $action, array $knownSkillIds, string $phaseName): void
    {
        if (! array_key_exists('blocking_prerequisite_skill_ids', $action)
            || $action['blocking_prerequisite_skill_ids'] === null
        ) {
            return;
        }

        $blockingSkillIds = $action['blocking_prerequisite_skill_ids'];

        if (! is_array($blockingSkillIds) || ! array_is_list($blockingSkillIds)) {
            throw new IntelligenceException(
                'The intelligence service response contains blocking_prerequisite_skill_ids in an invalid format.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['field' => 'blocking_prerequisite_skill_ids', 'phase' => $phaseName],
            );
        }

        $seen = [];

        foreach ($blockingSkillIds as $skillId) {
            if (! is_int($skillId)) {
                throw new IntelligenceException(
                    'The intelligence service response contains a non-integer blocking prerequisite skill id.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['skill_id' => $skillId, 'phase' => $phaseName],
                );
            }

            if (in_array($skillId, $seen, true)) {
                throw new IntelligenceException(
                    'The intelligence service response contains duplicate blocking prerequisite skill ids.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['skill_id' => $skillId, 'phase' => $phaseName],
                );
            }

            $seen[] = $skillId;

            if (! in_array($skillId, $knownSkillIds, true)) {
                throw new IntelligenceException(
                    'The intelligence service response contains an unknown blocking prerequisite skill.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['skill_id' => $skillId, 'phase' => $phaseName],
                );
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

    private function validateConfigurationVersion(array $result): void
    {
        if (
            ! array_key_exists('configuration_version', $result)
            || ! is_string($result['configuration_version'])
            || trim($result['configuration_version']) === ''
        ) {
            throw new IntelligenceException(
                'The intelligence service response is missing valid configuration version metadata.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['missing_field' => 'configuration_version'],
            );
        }
    }

    private function validateRoadmapTotals(array $result, mixed $weeklyAvailabilityHours): void
    {
        if (
            ! array_key_exists('estimated_total_hours', $result)
            || ! is_numeric($result['estimated_total_hours'])
            || (float) $result['estimated_total_hours'] < 0
        ) {
            throw new IntelligenceException(
                'The intelligence service response is missing a valid total effort estimate.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['field' => 'estimated_total_hours'],
            );
        }

        if (! array_key_exists('estimated_duration_weeks', $result)) {
            throw new IntelligenceException(
                'The intelligence service response is missing the roadmap calendar duration.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['missing_field' => 'estimated_duration_weeks'],
            );
        }

        $this->assertRoadmapDurationWeeks(
            $result['estimated_duration_weeks'],
            $weeklyAvailabilityHours,
            $result['estimated_total_hours'],
        );
    }

    private function validateLimitations(array $result): void
    {
        if (! array_key_exists('limitations', $result) || $result['limitations'] === null) {
            return;
        }

        if (! is_array($result['limitations']) || ! array_is_list($result['limitations'])) {
            throw new IntelligenceException(
                'The intelligence service response contains limitations in an invalid format.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['field' => 'limitations'],
            );
        }

        foreach ($result['limitations'] as $limitation) {
            if (! is_string($limitation) || trim($limitation) === '') {
                throw new IntelligenceException(
                    'The intelligence service response contains an invalid limitation entry.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['field' => 'limitations'],
                );
            }
        }
    }

    private function assertActionDurationWeeks(
        mixed $value,
        mixed $weeklyAvailabilityHours,
        string $phaseName,
    ): void {
        $weeklyHours = is_numeric($weeklyAvailabilityHours) ? (float) $weeklyAvailabilityHours : 0.0;

        if ($value === null) {
            if ($weeklyHours > 0) {
                throw new IntelligenceException(
                    'The intelligence service response omitted the action calendar duration for a learner with weekly availability.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['field' => 'estimated_duration_weeks', 'phase' => $phaseName],
                );
            }

            return;
        }

        if (! is_int($value) || $value <= 0) {
            throw new IntelligenceException(
                'The intelligence service response contains an invalid action calendar duration.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['field' => 'estimated_duration_weeks', 'phase' => $phaseName],
            );
        }
    }

    private function assertRoadmapDurationWeeks(
        mixed $value,
        mixed $weeklyAvailabilityHours,
        mixed $estimatedTotalHours,
    ): void {
        $weeklyHours = is_numeric($weeklyAvailabilityHours) ? (float) $weeklyAvailabilityHours : 0.0;
        $totalHours = is_numeric($estimatedTotalHours) ? (float) $estimatedTotalHours : 0.0;

        $mustBePositive = $totalHours > 0 && $weeklyHours > 0;

        if ($value === null) {
            if ($mustBePositive) {
                throw new IntelligenceException(
                    'The intelligence service response omitted the roadmap calendar duration for a roadmap with effort and weekly availability.',
                    502,
                    'INTELLIGENCE_INVALID_RESPONSE',
                    ['field' => 'estimated_duration_weeks', 'phase' => 'roadmap'],
                );
            }

            return;
        }

        if (! is_int($value) || $value < 0 || ($mustBePositive && $value === 0)) {
            throw new IntelligenceException(
                'The intelligence service response contains an invalid roadmap calendar duration.',
                502,
                'INTELLIGENCE_INVALID_RESPONSE',
                ['field' => 'estimated_duration_weeks', 'phase' => 'roadmap'],
            );
        }
    }

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
