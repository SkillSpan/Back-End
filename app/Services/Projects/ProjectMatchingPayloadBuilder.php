<?php

namespace App\Services\Projects;

use App\Models\ProjectMatchingSnapshot;

class ProjectMatchingPayloadBuilder
{
    public function build(ProjectMatchingSnapshot $snapshot): array
    {
        if ($snapshot->status !== ProjectMatchingSnapshot::STATUS_VALIDATED) {
            throw new \InvalidArgumentException(
                'Cannot build payload from snapshot with status: ' . $snapshot->status . '. Only validated snapshots can be built.'
            );
        }

        $data = $snapshot->snapshot;

        $payload = [
            'request_id' => (string) $snapshot->request_id,
            'algorithm_version' => $snapshot->algorithm_version,
            'configuration_version' => $snapshot->configuration_version,
            'project_version' => (int) $snapshot->project_version,
            'learner' => $this->buildLearnerContext($data),
            'project' => $this->buildProjectContext($data),
            'required_skills' => $this->buildRequiredSkills($data),
            'validation' => $this->buildValidationContext($data),
        ];

        return $payload;
    }

    protected function buildLearnerContext(array $data): array
    {
        $learner = $data['learner'] ?? [];
        $learnerSkills = $data['learner_skills'] ?? [];

        $context = [
            'user_id' => isset($learner['user_id']) ? (int) $learner['user_id'] : null,
            'student_profile_id' => isset($learner['student_profile_id']) ? (int) $learner['student_profile_id'] : null,
            'current_skills' => collect($learnerSkills)
                ->sortBy('skill_id')
                ->map(fn ($skill) => [
                    'skill_id' => (int) $skill['skill_id'],
                    'level' => (float) $skill['current_level'],
                    'required_level' => (float) $skill['required_level'],
                    'meets_requirement' => (bool) $skill['meets_requirement'],
                ])
                ->values()
                ->toArray(),
        ];

        if (isset($learner['availability']) && $learner['availability'] !== null) {
            $context['availability'] = $learner['availability'];
        }

        if (isset($learner['preferred_work_type']) && $learner['preferred_work_type'] !== null) {
            $context['preferred_work_type'] = $learner['preferred_work_type'];
        }

        if (isset($data['career_role']) && $data['career_role'] !== null) {
            $careerRole = $data['career_role'];
            $context['target_career_role'] = [
                'id' => (int) $careerRole['id'],
                'title' => (string) $careerRole['title'],
                'version' => (int) $careerRole['version'],
            ];
        }

        if (isset($data['interests']) && $data['interests'] !== null) {
            $context['interests'] = $data['interests'];
        }

        return $context;
    }

    protected function buildProjectContext(array $data): array
    {
        $project = $data['project'] ?? [];

        return [
            'id' => isset($project['id']) ? (int) $project['id'] : null,
            'version' => isset($project['version']) ? (int) $project['version'] : null,
            'type' => $project['type'] ?? null,
            'domain' => $project['domain'],
            'difficulty' => isset($project['difficulty']) ? (float) $project['difficulty'] : null,
            'work_mode' => $project['work_mode'],
            'role' => $project['role'],
            'schedule' => $project['schedule'],
            'organization_id' => isset($project['organization_id']) ? (int) $project['organization_id'] : null,
            'confidentiality' => $project['confidentiality'] ?? null,
        ];
    }

    protected function buildRequiredSkills(array $data): array
    {
        $requiredSkills = $data['required_skills'] ?? [];

        return collect($requiredSkills)
            ->sortBy('skill_id')
            ->map(fn ($skill) => [
                'skill_id' => (int) $skill['skill_id'],
                'skill_name' => $skill['skill_name'] ?? null,
                'minimum_level' => isset($skill['minimum_level']) ? (float) $skill['minimum_level'] : null,
                'is_critical_entry' => (bool) $skill['is_critical_entry'],
            ])
            ->values()
            ->toArray();
    }

    protected function buildValidationContext(array $data): array
    {
        $validation = $data['validation'] ?? [];

        $context = [
            'eligibility_state' => ($validation['eligible'] ?? false) ? 'eligible' : 'not_eligible',
            'validation_state' => ($validation['available'] ?? false) ? 'validated' : 'not_validated',
        ];

        $constraints = collect($data['eligibility_constraints'] ?? [])
            ->map(fn ($constraint) => [
                'type' => $constraint['constraint_type'],
                'value' => $constraint['value'],
                'blocking' => false,
            ])
            ->values()
            ->toArray();

        $skillFailures = collect($validation['skill_failures'] ?? [])
            ->map(fn ($failure) => [
                'skill_id' => isset($failure['skill_id']) ? (int) $failure['skill_id'] : null,
                'required_level' => isset($failure['required_level']) ? (float) $failure['required_level'] : null,
                'learner_level' => isset($failure['learner_level']) ? (float) $failure['learner_level'] : null,
                'reason' => $failure['reason'],
            ])
            ->values()
            ->toArray();

        $allConstraints = array_merge($constraints, $skillFailures);
        $context['constraints'] = collect($allConstraints)
            ->sortBy('type')
            ->values()
            ->toArray();

        $context['skill_gaps'] = $skillFailures;

        if (isset($validation['eligibility_reasons']) && $validation['eligibility_reasons'] !== null) {
            $context['eligibility_reasons'] = $validation['eligibility_reasons'];
        }

        if (isset($validation['authorization']) && $validation['authorization'] !== null) {
            $context['authorization'] = $validation['authorization'];
        }

        return $context;
    }
}
