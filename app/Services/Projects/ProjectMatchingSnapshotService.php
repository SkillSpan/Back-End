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

/**
 * Task 8 — validated project matching snapshot.
 *
 * Captures the validated input state that will be passed to the matching
 * layer (Task 9: FastAPI payload → Task 10: matching integration).
 *
 * Flow:
 *   1. Validate availability (reuse ProjectAvailabilityService)
 *   2. Validate authorization (reuse ProjectController rules)
 *   3. Validate eligibility (reuse ProjectEligibilityService)
 *   4. Load learner/project context
 *   5. Capture project version + algorithm/configuration versions
 *   6. Create the validated snapshot representation
 *
 * Refuses snapshot creation when any validation fails, returning a typed
 * domain-level exception so the caller can distinguish unavailable /
 * unauthorized / ineligible.
 */
class ProjectMatchingSnapshotService
{
    public function __construct(
        private readonly ProjectAvailabilityService $availabilityService = new ProjectAvailabilityService(),
        private readonly ProjectEligibilityService $eligibilityService = new ProjectEligibilityService(),
    ) {}

    /**
     * Create a validated project-matching snapshot.
     *
     * @throws IntelligenceException When the project is unavailable, the
     *                               learner is unauthorized, or the learner
     *                               is ineligible. The exception codeName
     *                               distinguishes the failure reason.
     */
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

        // 1. Validate availability.
        $availability = $this->availabilityService->check($project);

        if (! $availability->available) {
            throw new IntelligenceException(
                'The project is not available for matching.',
                422,
                'PROJECT_MATCH_UNAVAILABLE',
                ['reasons' => $availability->reasons],
            );
        }

        // 2. Validate authorization (mirror ProjectController logic).
        if (! $this->isAuthorized($project, $learner)) {
            throw new IntelligenceException(
                'The learner is not authorized to access this project.',
                403,
                'PROJECT_MATCH_UNAUTHORIZED',
            );
        }

        // 3. Validate eligibility.
        $eligibility = $this->eligibilityService->check($project, $learner);

        if (! $eligibility->eligible) {
            throw new IntelligenceException(
                'The learner is not eligible for this project.',
                422,
                'PROJECT_MATCH_INELIGIBLE',
                ['reasons' => $eligibility->reasons],
            );
        }

        // 4-5. Resolve versions and load context.
        $configuration = $this->resolveConfiguration();

        $projectVersion = (int) $project->version;
        $algorithmVersion = $configuration->version;
        $configurationVersion = 'project-matching-v1';

        $requestId = (string) Str::uuid();

        // 6. Build snapshot payload.
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

    /**
     * Check authorization: public projects are visible to all learners;
     * restricted projects are visible only to members of the owning
     * organization. Mirrors ProjectController logic.
     */
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

    /**
     * Resolve the active algorithm configuration.
     * Reuses the same pattern as DecisionSnapshotService::resolveConfiguration().
     */
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

    /**
     * Build the validated snapshot payload capturing learner context,
     * project context, validation results, and version info.
     */
    private function buildSnapshot(
        Project $project,
        StudentProfile $studentProfile,
        User $learner,
        object $availability,
        object $eligibility,
        string $algorithmVersion,
        string $configurationVersion,
    ): array {
        // Load required skills with their skill definitions.
        $project->loadMissing(['requiredSkills.skill', 'eligibilityConstraints']);

        $requiredSkills = $project->requiredSkills->map(function (ProjectRequiredSkill $requiredSkill) {
            return [
                'skill_id' => (int) $requiredSkill->skill_id,
                'skill_name' => $requiredSkill->skill?->name ?? null,
                'minimum_level' => (float) $requiredSkill->minimum_level,
                'is_critical_entry' => (bool) $requiredSkill->is_critical_entry,
            ];
        })->values()->toArray();

        // Load learner skill levels from authoritative sources.
        $learnerSkills = $this->gatherLearnerSkillContext($learner, $studentProfile, $project);

        // Capture career-role context if available.
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

    /**
     * Gather the learner's current skill levels for all project-required
     * skills, using the same authoritative source as eligibility: the
     * latest SkillEvaluation, with LearnerSkill as fallback.
     *
     * @return list<array<string, mixed>>
     */
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

    /**
     * Get the learner's current verified skill level.
     * Reuses the same logic as ProjectEligibilityService.
     */
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
