<?php

namespace App\Services\Projects;

use App\Models\LearnerSkill;
use App\Models\Project;
use App\Models\ProjectRequiredSkill;
use App\Models\ProjectTeamMember;
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

    public function check(Project $project, User $learner): object
    {
        $reasons = [];
        $skillFailures = [];

        $availabilityCheck = $this->checkCriticalSkills(
            $project,
            $learner,
            $skillFailures
        );

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

        $result = new stdClass;
        $result->eligible = empty($reasons);
        $result->reasons = $reasons;
        $result->skill_failures = $skillFailures;

        return $result;
    }

    private function checkCriticalSkills(
        Project $project,
        User $learner,
        array &$skillFailures
    ): bool {
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
            $learnerLevel = $this->getLearnerSkillLevel(
                $learner,
                $requiredSkill->skill_id,
                $studentProfile
            );

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

    private function getLearnerSkillLevel(
        User $learner,
        int $skillId,
        ?StudentProfile $studentProfile
    ): ?float {
        $studentProfileId = $studentProfile?->id
            ?? $learner->studentProfile?->id;

        if ($studentProfileId !== null) {
            $evaluation = SkillEvaluation::where('skill_id', $skillId)
                ->where('student_profile_id', $studentProfileId)
                ->orderByDesc('calculated_at')
                ->orderByDesc('id')
                ->first();

            if ($evaluation !== null) {
                return (float) $evaluation->level;
            }
        }

        $learnerSkill = LearnerSkill::where('learner_id', $learner->id)
            ->where('skill_id', $skillId)
            ->first();

        return $learnerSkill !== null
            ? (float) $learnerSkill->level
            : null;
    }

    private function checkEligibilityConstraints(
        Project $project,
        User $learner
    ): array {
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

            $learnerValue = $this->getLearnerValueForConstraint(
                $type,
                $studentProfile
            );

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

    private function canEnforceConstraint(
        string $constraintType,
        ?StudentProfile $studentProfile
    ): bool {
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

    private function getLearnerValueForConstraint(
        string $constraintType,
        ?StudentProfile $studentProfile
    ): ?string {
        if ($studentProfile === null) {
            return null;
        }

        return match ($constraintType) {
            'work_mode' => $studentProfile->preferred_work_type,
            'schedule' => $studentProfile->availability,
            default => null,
        };
    }

    private function checkDuplicateActiveApplication(
        Project $project,
        User $learner
    ): bool {
        return ! $learner->applications()
            ->where('project_id', $project->id)
            ->whereIn('status', [
                'submitted',
                'shortlisted',
                'accepted',
                'waitlisted',
            ])
            ->exists();
    }

    private function checkActiveAssignmentConflict(
        Project $project,
        User $learner
    ): bool {
        return ! ProjectTeamMember::whereHas('team', function ($q) use ($project) {
            $q->where('project_id', $project->id);
        })
            ->where('user_id', $learner->id)
            ->where('assignment_state', 'active')
            ->exists();
    }
}
