<?php

namespace App\Services\Projects;

use App\Exceptions\IntelligenceException;
use App\Models\AlgorithmConfiguration;
use App\Models\LearnerSkill;
use App\Models\Project;
use App\Models\ProjectMatchingSnapshot;
use App\Models\ProjectRequiredSkill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Str;

class ProjectMatchingSnapshotService
{
    public function __construct(
        private readonly ProjectAvailabilityService $availabilityService = new ProjectAvailabilityService,
        private readonly ProjectEligibilityService $eligibilityService = new ProjectEligibilityService,
    ) {}

    public function createForProject(Project $project, User $learner): ProjectMatchingSnapshot
    {
        $studentProfile = $learner->studentProfile;

        if ($studentProfile === null) {
            throw new IntelligenceException(
                'The learner does not have a student profile and cannot be matched to projects.',
                422,
                'PROJECT_MATCH_NO_STUDENT_PROFILE',
            );
        }

        $availability = $this->availabilityService->check($project);

        if (! $availability->available) {
            throw new IntelligenceException(
                'The project is not available for matching.',
                422,
                'PROJECT_MATCH_UNAVAILABLE',
                ['reasons' => $availability->reasons],
            );
        }

        if (! $this->isAuthorized($project, $learner)) {
            throw new IntelligenceException(
                'The learner is not authorized to access this project.',
                403,
                'PROJECT_MATCH_UNAUTHORIZED',
            );
        }

        $eligibility = $this->eligibilityService->check($project, $learner);

        if (! $eligibility->eligible) {
            throw new IntelligenceException(
                'The learner is not eligible for this project.',
                422,
                'PROJECT_MATCH_INELIGIBLE',
                ['reasons' => $eligibility->reasons],
            );
        }

        $configuration = $this->resolveConfiguration();

        $projectVersion = (int) $project->version;
        $algorithmVersion = 'project-matching-v1';
        $configurationVersion = 'project-matching-config-v1';

        $requestId = (string) Str::uuid();

        $snapshot = $this->buildSnapshot(
            $project,
            $studentProfile,
            $learner,
            $availability,
            $eligibility,
            (string) $algorithmVersion,
            $configurationVersion,
        );

        return ProjectMatchingSnapshot::create([
            'project_id' => $project->id,
            'student_profile_id' => $studentProfile->id,
            'project_version' => $projectVersion,
            'algorithm_version' => (string) $algorithmVersion,
            'configuration_version' => $configurationVersion,
            'request_id' => $requestId,
            'snapshot' => $snapshot,
            'status' => ProjectMatchingSnapshot::STATUS_VALIDATED,
            'validated_at' => now(),
        ]);
    }

    private function isAuthorized(Project $project, User $learner): bool
    {
        if ($project->confidentiality === 'public') {
            return true;
        }

        if ($project->confidentiality === 'restricted') {
            return $learner->organizations()
                ->where('organization_id', $project->organization_id)
                ->wherePivot('status', 'active')
                ->exists();
        }

        return false;
    }

    private function resolveConfiguration(): AlgorithmConfiguration
    {
        $configuration = AlgorithmConfiguration::query()
            ->where('status', 'active')
            ->orderByDesc('version')
            ->first();

        if (! $configuration) {
            throw new IntelligenceException(
                'No active algorithm configuration is available for project matching.',
                422,
                'PROJECT_MATCH_NO_CONFIG',
            );
        }

        return $configuration;
    }

    private function buildSnapshot(
        Project $project,
        StudentProfile $studentProfile,
        User $learner,
        object $availability,
        object $eligibility,
        string $algorithmVersion,
        string $configurationVersion,
    ): array {
        $project->loadMissing(['requiredSkills.skill', 'eligibilityConstraints']);

        $requiredSkills = $project->requiredSkills->map(function (ProjectRequiredSkill $requiredSkill) {
            return [
                'skill_id' => (int) $requiredSkill->skill_id,
                'skill_name' => $requiredSkill->skill?->name ?? null,
                'minimum_level' => (float) $requiredSkill->minimum_level,
                'is_critical_entry' => (bool) $requiredSkill->is_critical_entry,
            ];
        })->values()->toArray();

        $learnerSkills = $this->gatherLearnerSkillContext($learner, $studentProfile, $project);

        $careerRoleContext = null;
        if ($studentProfile->primary_career_role_id) {
            $careerRole = $studentProfile->relationLoaded('primaryCareerRole')
                ? $studentProfile->primaryCareerRole
                : $studentProfile->primaryCareerRole()->first();

            if ($careerRole) {
                $careerRoleContext = [
                    'id' => (int) $careerRole->id,
                    'title' => (string) $careerRole->title,
                    'version' => (int) $careerRole->version,
                ];
            }
        }

        return [
            'learner' => [
                'user_id' => (int) $learner->id,
                'student_profile_id' => (int) $studentProfile->id,
                'availability' => $studentProfile->availability,
                'preferred_work_type' => $studentProfile->preferred_work_type,
                'interests' => $studentProfile->interests,
            ],
            'project' => [
                'id' => (int) $project->id,
                'version' => (int) $project->version,
                'type' => (string) $project->type,
                'domain' => $project->domain,
                'difficulty' => $project->difficulty !== null ? (float) $project->difficulty : null,
                'work_mode' => $project->work_mode,
                'role' => $project->role,
                'schedule' => $project->schedule,
                'organization_id' => $project->organization_id,
                'confidentiality' => (string) $project->confidentiality,
            ],
            'required_skills' => $requiredSkills,
            'learner_skills' => $learnerSkills,
            'eligibility_constraints' => $project->eligibilityConstraints->map(function ($constraint) {
                return [
                    'constraint_type' => (string) $constraint->constraint_type,
                    'value' => (string) $constraint->value,
                ];
            })->values()->toArray(),
            'career_role' => $careerRoleContext,
            'validation' => [
                'available' => $availability->available,
                'availability_reasons' => $availability->reasons,
                'eligible' => $eligibility->eligible,
                'eligibility_reasons' => $eligibility->reasons,
                'skill_failures' => $eligibility->skill_failures,
                'authorization' => 'authorized',
            ],
            'algorithm_version' => $algorithmVersion,
            'configuration_version' => $configurationVersion,
        ];
    }

    private function gatherLearnerSkillContext(User $learner, StudentProfile $studentProfile, Project $project): array
    {
        $skills = [];

        foreach ($project->requiredSkills as $requiredSkill) {
            $level = $this->getLearnerSkillLevel($learner, $requiredSkill->skill_id, $studentProfile);

            if ($level !== null) {
                $skills[] = [
                    'skill_id' => (int) $requiredSkill->skill_id,
                    'current_level' => (float) $level,
                    'required_level' => (float) $requiredSkill->minimum_level,
                    'is_critical_entry' => (bool) $requiredSkill->is_critical_entry,
                    'meets_requirement' => (float) $level >= (float) $requiredSkill->minimum_level,
                ];
            }
        }

        return $skills;
    }

    private function getLearnerSkillLevel(User $learner, int $skillId, StudentProfile $studentProfile): ?float
    {
        $evaluation = SkillEvaluation::where('skill_id', $skillId)
            ->where('student_profile_id', $studentProfile->id)
            ->orderByDesc('calculated_at')
            ->orderByDesc('id')
            ->first();

        if ($evaluation !== null) {
            return (float) $evaluation->level;
        }

        $learnerSkill = LearnerSkill::where('learner_id', $learner->id)
            ->where('skill_id', $skillId)
            ->first();

        if ($learnerSkill !== null) {
            return (float) $learnerSkill->level;
        }

        return null;
    }
}
