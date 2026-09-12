<?php

namespace App\Services\Readiness;

use App\Models\Project;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;

class PracticalExperienceService
{
    private const ALGORITHM_VERSION = 'practical-experience-v1';

    public function calculate(StudentProfile $studentProfile): array
    {
        $studentId = (int) $studentProfile->user_id;
        $fullCreditProjectCount = max((int) config('readiness.practical_experience.full_credit_project_count', 2), 1);
        $eligibleProjects = $this->getEligibleProjects($studentId);
        $eligibleCount = $eligibleProjects->count();

        if ($eligibleCount === 0) {
            return [
                'score' => null,
                'eligible_count' => 0,
                'full_credit_project_count' => $fullCreditProjectCount,
                'algorithm_version' => self::ALGORITHM_VERSION,
                'config_version' => config('readiness.practical_experience.config_version', 'practical-experience-config-v1'),
            ];
        }

        return [
            'score' => round(min($eligibleCount / $fullCreditProjectCount, 1.0) * 100, 2),
            'eligible_count' => $eligibleCount,
            'full_credit_project_count' => $fullCreditProjectCount,
            'algorithm_version' => self::ALGORITHM_VERSION,
            'config_version' => config('readiness.practical_experience.config_version', 'practical-experience-config-v1'),
        ];
    }

    private function getEligibleProjects(int $studentId): Collection
    {
        return Project::query()
            ->where('projects.status', 'completed')
            ->join('project_teams', 'project_teams.project_id', '=', 'projects.id')
            ->join('project_team_members', function ($query) use ($studentId) {
                $query->on('project_team_members.project_team_id', '=', 'project_teams.id')
                    ->where('project_team_members.user_id', $studentId)
                    ->where('project_team_members.assignment_state', 'completed');
            })
            ->join('submissions', function ($query) use ($studentId) {
                $query->on('submissions.project_id', '=', 'projects.id')
                    ->where('submissions.contributor_id', $studentId)
                    ->where('submissions.status', 'accepted');
            })
            // FIX: was joining evaluations on project_id only, so ANY
            // finalized/moderated evaluation on the project (e.g. a
            // teammate's) made this student's own project count as
            // eligible. Now scoped to an evaluation of this student's
            // own accepted submission specifically.
            ->join('evaluations', function ($query) {
                $query->on('evaluations.submission_id', '=', 'submissions.id')
                    ->whereIn('evaluations.status', ['finalized', 'moderated']);
            })
            ->select('projects.id')
            ->distinct()
            ->get();
    }
}
