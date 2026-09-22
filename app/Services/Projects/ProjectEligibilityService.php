<?php

namespace App\Services\Projects;

use App\Models\LearnerSkill;
use App\Models\Project;
use App\Models\ProjectRequiredSkill;
use App\Models\SkillEvaluation;
use App\Models\StudentProfile;
use App\Models\User;
use stdClass;

class ProjectEligibilityService
{
    public function isEligible(Project $project, User $learner): bool
    {
        return $this->check($project, $learner)->eligible;
    }

    /**
     * Check whether a learner satisfies the hard eligibility
     * requirements for a project.
     *
     * Eligibility is checked AFTER availability and authorization.
     * This service does NOT:
     *   - check project availability (use ProjectAvailabilityService)
     *   - check learner authorization (handled by ProjectController)
     *   - calculate matching/ranking scores
     *
     * Rules implemented:
     *   1. Critical required skills: learner must have a skill
     *      evaluation whose level >= the project's minimum_level.
     *   2. Project eligibility constraints: work_mode, schedule,
     *      location, language — only if both the constraint and
     *      learner profile data are present and comparable.
     *   3. Duplicate active application: learner must not have a
     *      pending/active application to the same project.
     *   4. Conflicting active assignment: learner must not be
     *      actively assigned to a team on the same project.
     *
     * Non-critical required skills are NOT hard eligibility
     * blockers — they are matching inputs reserved for later tasks.
     */
    public function check(Project $project, User $learner): object
    {
        $reasons = [];
        $skillFailures = [];

        $availabilityCheck = $this->checkCriticalSkills($project, $learner, $skillFailures);
        if (! $availabilityCheck) {
            $reasons[] = 'One or more critical required skills are missing or below the required level.';
        }

        $constraintCheck = $this->checkEligibilityConstraints($project, $learner);
        if (! $constraintCheck['passed']) {
            $reasons = array_merge($reasons, $constraintCheck['reasons']);
        }

        $duplicateCheck = $this->checkDuplicateActiveApplication($project, $learner);
        if (! $duplicateCheck) {
            $reasons[] = 'The learner already has an active application for this project.';
        }

        $conflictCheck = $this->checkActiveAssignmentConflict($project, $learner);
        if (! $conflictCheck) {
            $reasons[] = 'The learner already has an active assignment on this project.';
        }

        $result = new stdClass();
        $result->eligible = empty($reasons);
        $result->reasons = $reasons;
        $result->skill_failures = $skillFailures;

        return $result;
    }

    /**
     * Check that every critical required skill is satisfied.
     *
     * Uses skill_evaluations (via the student profile) as the
     * authoritative source of the learner's current skill level.
     * Falls back to the learner_skills read projection when no
     * evaluation exists, so eligibility can still be checked
     * after an evaluation has been calculated.
     *
     * @param  array  $skillFailures  Filled with details of failed skills.
     * @return bool
     */
    private function checkCriticalSkills(Project $project, User $learner, array &$skillFailures): bool
    {
        $criticalSkills = $project->relationLoaded('requiredSkills')
            ? $project->requiredSkills->where('is_critical_entry', true)
            : ProjectRequiredSkill::where('project_id', $project->id)
                ->where('is_critical_entry', true)
                ->get();

        if ($criticalSkills->isEmpty()) {
            return true;
        }

        $studentProfile = $learner->studentProfile;

        foreach ($criticalSkills as $requiredSkill) {
            $learnerLevel = $this->getLearnerSkillLevel($learner, $requiredSkill->skill_id, $studentProfile);

            if ($learnerLevel === null) {
                $skillFailures[] = [
                    'skill_id' => $requiredSkill->skill_id,
                    'required_level' => (float) $requiredSkill->minimum_level,
                    'learner_level' => null,
                    'reason' => 'Skill evaluation not found for a critical required skill.',
                ];

                continue;
            }

            if ($learnerLevel < (float) $requiredSkill->minimum_level) {
                $skillFailures[] = [
                    'skill_id' => $requiredSkill->skill_id,
                    'required_level' => (float) $requiredSkill->minimum_level,
                    'learner_level' => (float) $learnerLevel,
                    'reason' => 'Learner skill level is below the required minimum for a critical skill.',
                ];
            }
        }

        return empty($skillFailures);
    }

    /**
     * Get the learner's current verified skill level.
     *
     * Authoritative source: the latest SkillEvaluation for the
     * student profile. Falls back to the LearnerSkill read
     * projection level when no evaluation exists.
     */
    private function getLearnerSkillLevel(User $learner, int $skillId, ?StudentProfile $studentProfile): ?float
    {
        if ($studentProfile === null) {
            $evaluation = SkillEvaluation::where('skill_id', $skillId)
                ->where('student_profile_id', $learner->studentProfile()->value('id'))
                ->orderByDesc('calculated_at')
                ->orderByDesc('id')
                ->first();

            if ($evaluation !== null) {
                return (float) $evaluation->level;
            }
        }

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

    /**
     * Check project eligibility constraints (work_mode, schedule,
     * location, language) against the learner's student profile.
     *
     * Only constraints that can be reliably validated against
     * existing student profile data are enforced. Unsupported
     * constraint types (location, language) are skipped because
     * the student profile does not store comparable fields.
     */
    private function checkEligibilityConstraints(Project $project, User $learner): array
    {
        $reasons = [];
        $studentProfile = $learner->studentProfile;

        $constraints = $project->relationLoaded('eligibilityConstraints')
            ? $project->eligibilityConstraints
            : $project->eligibilityConstraints()->get();

        if ($constraints->isEmpty()) {
            return ['passed' => true, 'reasons' => []];
        }

        foreach ($constraints as $constraint) {
            $type = $constraint->constraint_type;
            $requiredValue = $constraint->value;

            if (! $this->canEnforceConstraint($type, $studentProfile)) {
                continue;
            }

            $learnerValue = $this->getLearnerValueForConstraint($type, $studentProfile);

            if ($learnerValue === null) {
                $reasons[] = "Learner profile does not specify a {$type} preference matching the project requirement.";
                continue;
            }

            if ($learnerValue !== $requiredValue) {
                $reasons[] = "Learner's {$type} ({$learnerValue}) does not match the project's required {$type} ({$requiredValue}).";
            }
        }

        return ['passed' => empty($reasons), 'reasons' => $reasons];
    }

    /**
     * Determine whether a constraint type can be enforced given
     * the current student profile schema.
     */
    private function canEnforceConstraint(string $constraintType, ?StudentProfile $studentProfile): bool
    {
        if ($studentProfile === null) {
            return false;
        }

        return match ($constraintType) {
            'work_mode' => true,
            'schedule' => true,
            'location' => false,
            'language' => false,
            default => false,
        };
    }

    /**
     * Get the learner's value for a given constraint type.
     */
    private function getLearnerValueForConstraint(string $constraintType, ?StudentProfile $studentProfile): ?string
    {
        if ($studentProfile === null) {
            return null;
        }

        return match ($constraintType) {
            'work_mode' => $studentProfile->preferred_work_type,
            'schedule' => $studentProfile->availability,
            default => null,
        };
    }

    /**
     * Check whether the learner already has an active (non-terminal)
     * application to the same project.
     *
     * Active application statuses: submitted, shortlisted, accepted,
     * waitlisted. Submitted applications that have not been withdrawn
     * count as an active application.
     */
    private function checkDuplicateActiveApplication(Project $project, User $learner): bool
    {
        return ! $learner->applications()
            ->where('project_id', $project->id)
            ->whereIn('status', ['submitted', 'shortlisted', 'accepted', 'waitlisted'])
            ->exists();
    }

    /**
     * Check whether the learner already has an active assignment on
     * the same project via project team membership.
     */
    private function checkActiveAssignmentConflict(Project $project, User $learner): bool
    {
        return ! \App\Models\ProjectTeamMember::whereHas('team', function ($q) use ($project) {
            $q->where('project_id', $project->id);
        })
            ->where('user_id', $learner->id)
            ->where('assignment_state', 'active')
            ->exists();
    }
}
